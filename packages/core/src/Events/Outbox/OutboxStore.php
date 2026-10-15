<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use LogicException;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\Outbox\Exceptions\LeaseLostException;

/**
 * The tables of the outbox (D29) as the relay reads and writes them: v_outbox, the consumer rows and the
 * delivery rows. Every state change a worker makes on a row it leased is fenced by the lease generation it
 * leased with; a write that matches no row means the lease was taken over and the result is discarded.
 *
 * It opens its own short transactions; the relay calls a consumer outside of them.
 *
 * @internal
 */
final readonly class OutboxStore
{
    public const string EVENTS = 'outbox';

    public const string CONSUMERS = 'outbox_consumers';

    public const string DELIVERIES = 'outbox_deliveries';

    /** Seqs one reconciliation transaction covers. */
    private const int RECONCILE_SPAN = 1000;

    /** Rows one retention statement deletes. */
    private const int PRUNE_CHUNK = 1000;

    public function __construct(
        private VaulterDatabase $database,
        private DatabaseConfig $tables,
    ) {}

    public function maxSeq(): int
    {
        return self::int($this->events()->max('outbox_seq'));
    }

    /** The highest seq of an event older than `$before`: no smaller seq can still commit (the settle window). */
    public function settledSeq(CarbonImmutable $before): int
    {
        return self::int($this->events()->where('created_at', '<', $before)->max('outbox_seq'));
    }

    /**
     * Registers the consumers of the sealed registry and pauses the rows of the ones it does not hold: a new
     * consumer starts active at the current seq; a paused one resumes where it stopped; a detached one starts
     * over at the current seq (its gap stays recorded until replayed or acknowledged).
     *
     * @param array<string, DeliveryClass> $registry consumer id => delivery class
     */
    public function register(array $registry, CarbonImmutable $now): void
    {
        $this->database->transaction(function () use ($registry, $now): void {
            $max = $this->maxSeq();
            foreach ($registry as $id => $class) {
                $inserted = $this->database->insertOnce($this->table(self::CONSUMERS), [
                    'consumer_id' => $id,
                    'delivery_class' => $class->value,
                    'state' => ConsumerState::Active->value,
                    'activation_seq' => $max,
                    'reconciled_through_seq' => $max,
                    'lease_generation' => 0,
                    'gaps' => '[]',
                    'created_at' => $now,
                    'updated_at' => $now,
                ], 'consumer_id');
                if ($inserted) {
                    continue;
                }
                $this->consumers()->where('consumer_id', $id)->where('state', ConsumerState::Paused->value)
                    ->update(['state' => ConsumerState::Active->value, 'paused_at' => null, 'delivery_class' => $class->value, 'updated_at' => $now]);
                $this->reattach(id: $id, class: $class, max: $max, now: $now);
                $this->consumers()->where('consumer_id', $id)->where('delivery_class', '!=', $class->value)
                    ->update(['delivery_class' => $class->value, 'updated_at' => $now]);
            }

            $missing = $this->consumers()->where('state', ConsumerState::Active->value);
            if ($registry !== []) {
                $missing->whereNotIn('consumer_id', array_keys($registry));
            }
            $missing->update(['state' => ConsumerState::Paused->value, 'paused_at' => $now, 'updated_at' => $now]);
        });
    }

    /**
     * A detached consumer returns at the current head. Durable events committed while it was detached (above the
     * detach-time cursor) were never delivered: they join the gaps before the cursor jumps (O4).
     */
    private function reattach(string $id, DeliveryClass $class, int $max, CarbonImmutable $now): void
    {
        $row = $this->consumers()->where('consumer_id', $id)->where('state', ConsumerState::Detached->value)->lockForUpdate()->first();
        if ($row === null) {
            return;
        }
        $consumer = self::consumerRow((array) $row);
        $values = ['state' => ConsumerState::Active->value, 'activation_seq' => $max, 'reconciled_through_seq' => max($max, $consumer['reconciled_through_seq']), 'paused_at' => null, 'delivery_class' => $class->value, 'updated_at' => $now];
        if ($consumer['delivery_class'] === DeliveryClass::Durable->value) {
            $missed = $this->events()->where('outbox_seq', '>', $consumer['reconciled_through_seq'])->where('outbox_seq', '<=', $max);
            $count = (clone $missed)->count();
            if ($count > 0) {
                $from = self::int((clone $missed)->min('outbox_seq'));
                $values['gaps'] = json_encode([...$consumer['gaps'], [
                    'from_seq' => $from,
                    'to_seq' => max($max, $from),
                    'events' => $count,
                    'at' => $now->toIso8601String(),
                    'acknowledged_at' => null,
                ]], JSON_THROW_ON_ERROR);
            }
        }
        $this->consumers()->where('consumer_id', $id)->update($values);
    }

    /**
     * @return array{consumer_id: string, delivery_class: string, state: string, activation_seq: int, reconciled_through_seq: int, paused_at: string|null, lease_generation: int, gaps: list<array<string, mixed>>}|null
     */
    public function consumer(string $id): ?array
    {
        $row = $this->consumers()->where('consumer_id', $id)->first();

        return $row === null ? null : self::consumerRow((array) $row);
    }

    /** @return list<array{consumer_id: string, delivery_class: string, state: string, activation_seq: int, reconciled_through_seq: int, paused_at: string|null, lease_generation: int, gaps: list<array<string, mixed>>}> */
    public function consumerRows(): array
    {
        return array_values(array_map(
            static fn (object $row): array => self::consumerRow((array) $row),
            $this->consumers()->orderBy('consumer_id')->get()->all(),
        ));
    }

    /**
     * Leases the consumer to one reconciler: the fenced generation, or null while another relay holds a live lease
     * or the consumer is not active.
     */
    public function leaseConsumer(string $id, string $owner, CarbonImmutable $now, int $seconds): ?int
    {
        $leased = $this->consumers()
            ->where('consumer_id', $id)
            ->where('state', ConsumerState::Active->value)
            ->where(static fn (Builder $lease): Builder => $lease->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now))
            ->increment('lease_generation', 1, ['lease_owner' => $owner, 'lease_expires_at' => $now->addSeconds($seconds), 'updated_at' => $now]);
        if ($leased !== 1) {
            return null;
        }

        $generation = $this->consumers()->where('consumer_id', $id)->where('lease_owner', $owner)->value('lease_generation');

        return is_numeric($generation) ? (int) $generation : null;
    }

    public function releaseConsumer(string $id, int $generation): void
    {
        $this->consumers()->where('consumer_id', $id)->where('lease_generation', $generation)
            ->update(['lease_owner' => null, 'lease_expires_at' => null]);
    }

    /**
     * Materializes the delivery rows the consumer misses for events with `reconciled_through_seq < seq <= $through`
     * of the subscribed types, then advances reconciled_through_seq — fenced by the consumer lease, span by span.
     * Returns the rows inserted.
     *
     * @param list<string> $types the type values the consumer subscribes to
     *
     * @throws LeaseLostException when another reconciler took the consumer over
     */
    public function reconcile(string $id, int $generation, array $types, int $through, CarbonImmutable $now): int
    {
        $inserted = 0;
        while (true) {
            $consumer = $this->consumer($id) ?? throw new LeaseLostException('consumer '.$id.' disappeared');
            $from = $consumer['reconciled_through_seq'];
            if ($from >= $through) {
                return $inserted;
            }
            $to = min($through, $from + self::RECONCILE_SPAN);

            $inserted += $this->database->transaction(function () use ($id, $generation, $types, $from, $to, $now): int {
                $rows = 0;
                if ($types !== []) {
                    $rows = $this->insertMissing($id, $types, $from, $to, $now, onlyMissing: true);
                }
                $advanced = $this->consumers()->where('consumer_id', $id)->where('lease_generation', $generation)
                    ->where('reconciled_through_seq', $from)
                    ->update(['reconciled_through_seq' => $to, 'updated_at' => $now]);
                if ($advanced !== 1) {
                    throw new LeaseLostException('consumer '.$id.' was reconciled by another relay');
                }

                return $rows;
            });
        }
    }

    /**
     * Leases up to `$limit` due delivery rows of the consumer — pending ones whose next attempt is due and leased
     * ones whose lease expired — and returns them with the generation each one is now fenced by.
     *
     * @return list<array{event_id: string, generation: int, attempts: int, continuation: string|null}>
     */
    public function claim(string $consumer, string $owner, CarbonImmutable $now, int $seconds, int $limit): array
    {
        return $this->database->transaction(function () use ($consumer, $owner, $now, $seconds, $limit): array {
            $query = $this->deliveries()
                ->select(['event_id', 'lease_generation', 'attempts', 'continuation'])
                ->where('consumer_id', $consumer)
                ->where(static fn (Builder $due): Builder => $due
                    ->where(static fn (Builder $pending): Builder => $pending->where('state', DeliveryState::Pending->value)->where('next_attempt_at', '<=', $now))
                    ->orWhere(static fn (Builder $expired): Builder => $expired->where('state', DeliveryState::Leased->value)->where('lease_expires_at', '<=', $now)))
                ->orderBy('next_attempt_at')
                ->orderBy('outbox_seq')
                ->limit(max($limit, 1));
            $query = in_array($this->database->driver(), ['pgsql', 'mysql', 'mariadb'], true)
                ? $query->lock('for update skip locked')
                : $query->lockForUpdate();

            $claims = [];
            foreach ($query->get() as $row) {
                $row = (array) $row;
                $eventId = self::string($row['event_id']);
                $generation = self::int($row['lease_generation']);
                $leased = $this->deliveries()
                    ->where('event_id', $eventId)
                    ->where('consumer_id', $consumer)
                    ->where('lease_generation', $generation)
                    ->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Leased->value])
                    ->update([
                        'state' => DeliveryState::Leased->value,
                        'lease_owner' => $owner,
                        'lease_generation' => $generation + 1,
                        'lease_expires_at' => $now->addSeconds($seconds),
                        'updated_at' => $now,
                    ]);
                if ($leased === 1) {
                    $claims[] = [
                        'event_id' => $eventId,
                        'generation' => $generation + 1,
                        'attempts' => self::int($row['attempts']),
                        'continuation' => is_string($row['continuation']) ? $row['continuation'] : null,
                    ];
                }
            }

            return $claims;
        });
    }

    /**
     * Finishes a leased delivery under its generation: done, back to pending at `$retryAt`, or dead. False when the
     * lease was taken over — nothing is written then.
     */
    public function complete(string $eventId, string $consumer, int $generation, DeliveryState $state, CarbonImmutable $now, ?string $error = null, ?CarbonImmutable $retryAt = null): bool
    {
        $values = match ($state) {
            DeliveryState::Done => ['done_at' => $now, 'last_error' => null],
            DeliveryState::Dead => ['done_at' => $now, 'last_error' => $error, 'attempts' => $this->database->connection()->raw('attempts + 1')],
            DeliveryState::Pending => ['next_attempt_at' => $retryAt ?? $now, 'last_error' => $error, 'attempts' => $this->database->connection()->raw('attempts + 1')],
            DeliveryState::Leased => throw new LogicException('[Vaulter] A delivery is not completed as leased.'),
        };

        return $this->deliveries()
            ->where('event_id', $eventId)
            ->where('consumer_id', $consumer)
            ->where('state', DeliveryState::Leased->value)
            ->where('lease_generation', $generation)
            ->update([...$values, 'state' => $state->value, 'lease_owner' => null, 'lease_expires_at' => null, 'updated_at' => $now]) === 1;
    }

    /**
     * Detaches every consumer paused since before `$pausedBefore` (D29): its open rows become dead, and a durable one
     * records the range it missed as an explicit gap. Returns consumer id => events in the gap (0 for an ephemeral
     * consumer).
     *
     * @return array<string, int>
     */
    public function detachExpired(CarbonImmutable $pausedBefore, CarbonImmutable $now): array
    {
        $detached = [];
        $ids = $this->consumers()->where('state', ConsumerState::Paused->value)->where('paused_at', '<=', $pausedBefore)->pluck('consumer_id')->all();
        foreach ($ids as $id) {
            $id = self::string($id);
            $events = $this->database->transaction(function () use ($id, $pausedBefore, $now): ?int {
                $consumer = $this->consumers()->where('consumer_id', $id)->where('state', ConsumerState::Paused->value)->where('paused_at', '<=', $pausedBefore)->lockForUpdate()->first();
                if ($consumer === null) {
                    return null;
                }
                $consumer = self::consumerRow((array) $consumer);
                $open = $this->deliveries()->where('consumer_id', $id)->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Leased->value]);
                // Open rows of events above reconciled_through_seq are counted once, with the unreconciled events.
                $openCount = (clone $open)->where('outbox_seq', '<=', $consumer['reconciled_through_seq'])->count();
                $openMin = (clone $open)->min('outbox_seq');
                $open->update(['state' => DeliveryState::Dead->value, 'last_error' => 'consumer detached', 'done_at' => $now, 'lease_owner' => null, 'lease_expires_at' => null, 'updated_at' => $now]);

                $max = $this->maxSeq();
                $values = ['state' => ConsumerState::Detached->value, 'reconciled_through_seq' => max($max, $consumer['reconciled_through_seq']), 'lease_owner' => null, 'lease_expires_at' => null, 'updated_at' => $now];
                $missed = 0;
                if ($consumer['delivery_class'] === DeliveryClass::Durable->value) {
                    $pending = $this->events()->where('outbox_seq', '>', $consumer['reconciled_through_seq'])->where('outbox_seq', '<=', $max);
                    $unreconciled = (clone $pending)->count();
                    $missed = $openCount + $unreconciled;
                    if ($missed > 0) {
                        // The first seq actually missed: sequences have holes (rolled-back writes), so not reconciled + 1.
                        $firstUnreconciled = (clone $pending)->min('outbox_seq');
                        $from = min(is_numeric($firstUnreconciled) ? (int) $firstUnreconciled : PHP_INT_MAX, is_numeric($openMin) ? (int) $openMin : PHP_INT_MAX);
                        $values['gaps'] = json_encode([...$consumer['gaps'], [
                            'from_seq' => $from,
                            'to_seq' => max($max, $from),
                            'events' => $missed,
                            'at' => $now->toIso8601String(),
                            'acknowledged_at' => null,
                        ]], JSON_THROW_ON_ERROR);
                    }
                }
                $this->consumers()->where('consumer_id', $id)->update($values);

                return $missed;
            });
            if ($events !== null) {
                $detached[$id] = $events;
            }
        }

        return $detached;
    }

    /**
     * Retention (D29): deletes an event older than `$eventsBefore` only when no delivery row of it is open and every
     * durable consumer that is not detached reconciled past it (a paused one holds it until it is detached); then
     * the done rows of deleted events, and the dead rows older than `$deadBefore`.
     *
     * @return array{events: int, done: int, dead: int}
     */
    public function prune(CarbonImmutable $eventsBefore, CarbonImmutable $deadBefore): array
    {
        $hold = $this->consumers()
            ->where('delivery_class', DeliveryClass::Durable->value)
            ->where('state', '!=', ConsumerState::Detached->value)
            ->min('reconciled_through_seq');
        $events = $this->table(self::EVENTS);
        $deliveries = $this->table(self::DELIVERIES);

        // The delete repeats the predicate: a replay that reopened an event after the select keeps it.
        $prunable = function () use ($eventsBefore, $hold, $events, $deliveries): Builder {
            $query = $this->events()
                ->where('created_at', '<', $eventsBefore)
                ->whereNotExists(fn (Builder $open): Builder => $open->from($deliveries)
                    ->whereColumn($deliveries.'.event_id', $events.'.event_id')
                    ->whereIn($deliveries.'.state', [DeliveryState::Pending->value, DeliveryState::Leased->value]));
            if ($hold !== null) {
                $query->where('outbox_seq', '<=', self::int($hold));
            }

            return $query;
        };
        $deleted = 0;
        do {
            $seqs = $prunable()->orderBy('outbox_seq')->limit(self::PRUNE_CHUNK)->pluck('outbox_seq')->all();
            if ($seqs !== []) {
                $deleted += $prunable()->whereIn('outbox_seq', $seqs)->delete();
            }
        } while (count($seqs) === self::PRUNE_CHUNK);

        $done = $this->deliveries()
            ->where('state', DeliveryState::Done->value)
            ->whereNotExists(fn (Builder $event): Builder => $event->from($events)->whereColumn($events.'.event_id', $deliveries.'.event_id'))
            ->delete();
        $dead = $this->deliveries()->where('state', DeliveryState::Dead->value)->where('done_at', '<', $deadBefore)->delete();

        return ['events' => $deleted, 'done' => $done, 'dead' => $dead];
    }

    /**
     * Materializes the delivery rows of the consumer for the live events of `[$from, $to]` of the subscribed types
     * (a done or dead row goes back to pending with a fresh budget) and acknowledges the gaps the range covers.
     * Returns the seqs the range actually covered (null when no event of it is alive) and the rows made pending.
     *
     * @param  list<string>                                          $types
     * @return array{covered: array{0: int, 1: int}|null, rows: int}
     */
    public function replay(string $id, array $types, int $from, int $to, CarbonImmutable $now): array
    {
        return $this->database->transaction(function () use ($id, $types, $from, $to, $now): array {
            $live = $this->events()->where('outbox_seq', '>=', $from)->where('outbox_seq', '<=', $to);
            $first = (clone $live)->min('outbox_seq');
            $last = (clone $live)->max('outbox_seq');
            if ($first === null || $last === null || $types === []) {
                // Nothing alive in the range: the loss is acknowledged only explicitly (--ack-only), never by a replay.
                if ($first !== null && $last !== null) {
                    $this->acknowledge($id, $from, $to, $now);
                }

                return ['covered' => $first === null || $last === null ? null : [self::int($first), self::int($last)], 'rows' => 0];
            }

            $rows = $this->insertMissing($id, $types, $from - 1, $to, $now, onlyMissing: true);
            $rows += $this->deliveries()
                ->where('consumer_id', $id)
                ->where('outbox_seq', '>=', $from)
                ->where('outbox_seq', '<=', $to)
                ->whereIn('state', [DeliveryState::Done->value, DeliveryState::Dead->value])
                ->update(['state' => DeliveryState::Pending->value, 'attempts' => 0, 'next_attempt_at' => $now, 'continuation' => null, 'last_error' => null, 'done_at' => null, 'updated_at' => $now]);
            // A range whose head was already pruned is only partly replayed: its gaps stay open for --ack-only.
            if (self::int($first) <= $from) {
                $this->acknowledge($id, $from, $to, $now);
            }

            return ['covered' => [self::int($first), self::int($last)], 'rows' => $rows];
        });
    }

    /** Marks the gaps of the consumer that `[$from, $to]` covers as acknowledged. */
    public function acknowledge(string $id, int $from, int $to, CarbonImmutable $now): int
    {
        $consumer = $this->consumer($id);
        if ($consumer === null) {
            return 0;
        }
        $acknowledged = 0;
        $gaps = [];
        foreach ($consumer['gaps'] as $gap) {
            if (($gap['acknowledged_at'] ?? null) === null && self::int($gap['from_seq'] ?? 0) >= $from && self::int($gap['to_seq'] ?? PHP_INT_MAX) <= $to) {
                $gap['acknowledged_at'] = $now->toIso8601String();
                $acknowledged++;
            }
            $gaps[] = $gap;
        }
        if ($acknowledged > 0) {
            $this->consumers()->where('consumer_id', $id)->update(['gaps' => json_encode($gaps, JSON_THROW_ON_ERROR), 'updated_at' => $now]);
        }

        return $acknowledged;
    }

    /**
     * The oldest due open delivery per consumer: when its event was written, for the lag gauge and the doctor.
     *
     * @return array<string, CarbonImmutable> consumer id => created_at of the oldest pending or leased row
     */
    public function oldestOpen(CarbonImmutable $now): array
    {
        $oldest = [];
        $events = $this->table(self::EVENTS);
        $deliveries = $this->table(self::DELIVERIES);
        // A consumer the relay never registered still has rows: the ids come from the deliveries.
        foreach ($this->deliveries()->distinct()->pluck('consumer_id')->all() as $consumer) {
            $min = $this->deliveries()
                ->join($events, $events.'.event_id', '=', $deliveries.'.event_id')
                ->where($deliveries.'.consumer_id', $consumer)
                ->whereIn($deliveries.'.state', [DeliveryState::Pending->value, DeliveryState::Leased->value])
                ->where($deliveries.'.next_attempt_at', '<=', $now)
                ->min($events.'.created_at');
            if (is_string($min)) {
                $oldest[self::string($consumer)] = CarbonImmutable::parse($min);
            }
        }

        return $oldest;
    }

    /** Leased rows whose lease expired before `$before`: workers that died without a takeover yet. */
    public function stuckLeases(CarbonImmutable $before): int
    {
        return $this->deliveries()->where('state', DeliveryState::Leased->value)->where('lease_expires_at', '<', $before)->count();
    }

    public function connection(): Connection
    {
        return $this->database->connection();
    }

    /**
     * Inserts a pending row for each event of `($after, $to]` of the types that the consumer has no row for.
     *
     * @param list<string> $types
     */
    private function insertMissing(string $id, array $types, int $after, int $to, CarbonImmutable $now, bool $onlyMissing): int
    {
        $events = $this->table(self::EVENTS);
        $deliveries = $this->table(self::DELIVERIES);
        $select = $this->database->connection()->table($events)
            ->select(['event_id', 'outbox_seq'])
            ->where('outbox_seq', '>', $after)
            ->where('outbox_seq', '<=', $to)
            ->whereIn('type', $types);
        if ($onlyMissing) {
            $select->whereNotExists(fn (Builder $row): Builder => $row->from($deliveries)
                ->whereColumn($deliveries.'.event_id', $events.'.event_id')
                ->where($deliveries.'.consumer_id', $id));
        }

        $inserted = 0;
        foreach ($select->orderBy('outbox_seq')->get()->chunk(500) as $chunk) {
            $this->deliveries()->insert(array_values($chunk->map(static fn (object $event): array => [
                'event_id' => self::string($event->event_id),
                'consumer_id' => $id,
                'outbox_seq' => self::int($event->outbox_seq),
                'state' => DeliveryState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => $now,
                'lease_generation' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()));
            $inserted += $chunk->count();
        }

        return $inserted;
    }

    /**
     * @param  array<array-key, mixed>                                                                                                                                                                              $row
     * @return array{consumer_id: string, delivery_class: string, state: string, activation_seq: int, reconciled_through_seq: int, paused_at: string|null, lease_generation: int, gaps: list<array<string, mixed>>}
     */
    private static function consumerRow(array $row): array
    {
        $gaps = is_string($row['gaps'] ?? null) ? json_decode($row['gaps'], true) : [];

        return [
            'consumer_id' => self::string($row['consumer_id']),
            'delivery_class' => self::string($row['delivery_class']),
            'state' => self::string($row['state']),
            'activation_seq' => self::int($row['activation_seq']),
            'reconciled_through_seq' => self::int($row['reconciled_through_seq']),
            'paused_at' => is_string($row['paused_at'] ?? null) ? $row['paused_at'] : null,
            'lease_generation' => self::int($row['lease_generation']),
            'gaps' => self::gaps($gaps),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function gaps(mixed $decoded): array
    {
        $gaps = [];
        foreach (is_array($decoded) ? $decoded : [] as $gap) {
            if (is_array($gap)) {
                $gaps[] = array_filter($gap, is_string(...), ARRAY_FILTER_USE_KEY);
            }
        }

        return $gaps;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function table(string $entity): string
    {
        return $this->tables->table($entity);
    }

    private function events(): Builder
    {
        return $this->database->connection()->table($this->table(self::EVENTS));
    }

    private function consumers(): Builder
    {
        return $this->database->connection()->table($this->table(self::CONSUMERS));
    }

    private function deliveries(): Builder
    {
        return $this->database->connection()->table($this->table(self::DELIVERIES));
    }
}
