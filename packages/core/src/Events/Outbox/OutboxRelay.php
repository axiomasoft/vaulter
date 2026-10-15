<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use LogicException;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\OutboxConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\EventCatalog;
use Vaulter\Events\Outbox\Exceptions\LeaseLostException;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Kernel;
use Vaulter\Modules\SlotContribution;

/**
 * The relay of the outbox (D29), run by `vaulter:relay`. One pass:
 *
 *  1. Register — every consumer of the sealed registry gets its row (a new one starts at the current seq, a paused
 *     one resumes, a detached one starts over); rows of consumers the registry does not hold are paused.
 *  2. Reconcile — under the consumer lease, the rows a process with an older module graph missed are materialized
 *     for events up to S = the highest seq written before now − settle_window, and reconciled_through_seq moves to
 *     S. It never advances past S: a long transaction may still commit a smaller seq inside the window.
 *  3. Deliver — due rows are leased (SKIP LOCKED where the database has it), the consumer runs outside any
 *     transaction, and each result is written fenced by the lease generation: a worker whose lease was taken over
 *     writes nothing. A retry backs off with full jitter until the attempt budget is spent, then the row is dead.
 *  4. Hold — a consumer paused longer than max_consumer_hold is detached: its open rows die and a durable consumer
 *     records the range it missed as a gap (metric and doctor error until replayed or acknowledged).
 *
 * Retention and replay are separate commands ({@see prune()}, {@see replay()}).
 */
#[Internal]
final readonly class OutboxRelay
{
    public function __construct(
        private OutboxStore $store,
        private OutboxConfig $config,
        private VaulterConfig $vaulterConfig,
        private Kernel $kernel,
        private Container $container,
        private MetricsRecorder $metrics,
        private VaulterDatabase $database,
        private DatabaseConfig $tables,
    ) {}

    /**
     * One pass over the consumers (or only `$only`); returns the deliveries finished (done or dead) by consumer id.
     *
     * @return array<string, int>
     */
    public function pass(?string $only = null, ?int $limit = null): array
    {
        return $this->metrics->timing(
            name: 'vaulter_outbox_relay_duration_seconds',
            operation: function () use ($only, $limit): array {
                $consumers = $this->consumers();
                $now = CarbonImmutable::now();
                $this->store->register(array_map(static fn (OutboxConsumer $c): DeliveryClass => $c->deliveryClass(), $consumers), $now);

                $finished = [];
                foreach ($consumers as $id => $consumer) {
                    if ($only !== null && $only !== $id) {
                        continue;
                    }
                    $this->reconcile($id, $consumer, CarbonImmutable::now());
                    $finished[$id] = $this->deliver($id, $consumer, $limit ?? $this->config->batch());
                }

                foreach ($this->store->detachExpired(CarbonImmutable::now()->subSeconds($this->config->maxConsumerHoldSeconds()), CarbonImmutable::now()) as $id => $events) {
                    if ($events > 0) {
                        $this->metrics->counter(name: 'vaulter_outbox_consumer_gap_events_total', amount: $events, labels: ['consumer' => $id]);
                    }
                }
                $this->gauges(CarbonImmutable::now());

                return $finished;
            },
        );
    }

    /**
     * Retention: see {@see OutboxStore::prune()}.
     *
     * @return array{events: int, done: int, dead: int}
     */
    public function prune(): array
    {
        $now = CarbonImmutable::now();

        return $this->store->prune(
            eventsBefore: $now->subSeconds($this->config->retentionSeconds()),
            deadBefore: $now->subSeconds($this->config->deadRetentionSeconds()),
        );
    }

    /**
     * `vaulter:relay:replay`: re-delivers `[$from, $to]` to an active consumer of the registry, or with `$ackOnly`
     * only acknowledges the gaps the range covers.
     *
     * @return array{covered: array{0: int, 1: int}|null, rows: int, acknowledged: int}
     */
    public function replay(string $id, int $from, int $to, bool $ackOnly): array
    {
        $now = CarbonImmutable::now();
        if ($ackOnly) {
            return ['covered' => null, 'rows' => 0, 'acknowledged' => $this->store->acknowledge($id, $from, $to, $now)];
        }
        $consumer = $this->consumers()[$id] ?? throw new LogicException(sprintf('[Vaulter] The outbox consumer "%s" is not in the active module graph.', $id));
        $this->store->register(array_map(static fn (OutboxConsumer $c): DeliveryClass => $c->deliveryClass(), $this->consumers()), $now);
        $before = $this->store->consumer($id)['gaps'] ?? [];
        $result = $this->store->replay($id, $this->subscribedTypes($consumer), $from, $to, $now);
        $after = $this->store->consumer($id)['gaps'] ?? [];
        $acknowledged = count(array_filter($after, static fn (array $gap): bool => ($gap['acknowledged_at'] ?? null) !== null))
            - count(array_filter($before, static fn (array $gap): bool => ($gap['acknowledged_at'] ?? null) !== null));

        return [...$result, 'acknowledged' => $acknowledged];
    }

    /**
     * The consumers of the sealed registry, by contribution id.
     *
     * @return array<string, OutboxConsumer>
     */
    public function consumers(): array
    {
        $consumers = [];
        foreach ($this->kernel->extensions()->contributions(OutboxConsumer::class) as $contribution) {
            $consumers[$contribution->id] = $this->resolve($contribution);
        }

        return $consumers;
    }

    private function resolve(SlotContribution $contribution): OutboxConsumer
    {
        $consumer = $this->container->make($contribution->implementation);
        if (!$consumer instanceof OutboxConsumer || $consumer->id() !== $contribution->id) {
            throw new LogicException(sprintf('[Vaulter] The outbox consumer contributed as "%s" must implement %s and answer that id.', $contribution->id, OutboxConsumer::class));
        }

        return $consumer;
    }

    private function reconcile(string $id, OutboxConsumer $consumer, CarbonImmutable $now): void
    {
        $owner = $this->owner();
        $generation = $this->store->leaseConsumer($id, $owner, $now, $this->config->leaseSeconds());
        if ($generation === null) {
            return;
        }
        try {
            $through = $this->store->settledSeq($now->subSeconds($this->config->settleWindowSeconds()));
            $this->store->reconcile($id, $generation, $this->subscribedTypes($consumer), $through, $now);
        } catch (LeaseLostException) {
            return;
        } finally {
            $this->store->releaseConsumer($id, $generation);
        }
    }

    private function deliver(string $id, OutboxConsumer $consumer, int $limit): int
    {
        $now = CarbonImmutable::now();
        $claims = $this->store->claim($id, $this->owner(), $now, $this->config->leaseSeconds(), $limit);
        if ($claims === []) {
            return 0;
        }

        /** @var class-string<OutboxEvent> $model */
        $model = $this->vaulterConfig->model('outbox');
        $events = $model::query()->whereIn('event_id', array_column($claims, 'event_id'))->get()->keyBy('event_id');

        $finished = 0;
        foreach ($claims as $claim) {
            $event = $events->get($claim['event_id']);
            if (!$event instanceof OutboxEvent) {
                // Retention never deletes an event with an open row; a host that deleted it by hand loses it here.
                $finished += (int) $this->finish($id, $claim, ConsumeResult::dead('the outbox event is gone'));

                continue;
            }
            $control = new DeliveryControl($this->database, $this->tables, $claim['event_id'], $id, $claim['generation'], $claim['continuation']);
            try {
                $result = $consumer->consume(OutboxEnvelope::fromModel($event), $control);
            } catch (Throwable $exception) {
                report($exception);
                $result = ConsumeResult::retry($exception::class.': '.$exception->getMessage());
            }
            $finished += (int) $this->finish($id, $claim, $result);
        }

        return $finished;
    }

    /**
     * Writes the result fenced by the claim's generation; true when the row reached a terminal state.
     *
     * @param array{event_id: string, generation: int, attempts: int, continuation: string|null} $claim
     */
    private function finish(string $id, array $claim, ConsumeResult $result): bool
    {
        $now = CarbonImmutable::now();
        $attempts = $claim['attempts'] + 1;
        [$state, $retryAt] = match ($result->outcome) {
            ConsumeResult::DONE => [DeliveryState::Done, null],
            ConsumeResult::RETRY => $attempts >= $this->config->maxAttempts()
                ? [DeliveryState::Dead, null]
                : [DeliveryState::Pending, $now->addSeconds($this->backoff($attempts))],
            default => [DeliveryState::Dead, null],
        };

        $written = $this->store->complete($claim['event_id'], $id, $claim['generation'], $state, $now, $result->error, $retryAt);
        if (!$written) {
            // Taken over after the lease expired: the successor's result stands.
            $this->metrics->counter(name: 'vaulter_outbox_lease_lost_total', labels: ['consumer' => $id]);

            return false;
        }
        match ($state) {
            DeliveryState::Done => $this->metrics->counter(name: 'vaulter_outbox_relayed_total', labels: ['consumer' => $id]),
            DeliveryState::Dead => $this->metrics->counter(name: 'vaulter_outbox_dead_total', labels: ['consumer' => $id]),
            default => null,
        };

        return $state !== DeliveryState::Pending;
    }

    /** Full jitter: a random delay up to min(cap, base · 2^attempts). */
    private function backoff(int $attempts): int
    {
        $ceiling = min($this->config->backoffCapSeconds(), $this->config->backoffBaseSeconds() * (2 ** min($attempts, 30)));

        return random_int(0, max((int) $ceiling, 0));
    }

    /**
     * The type values of the catalogue the consumer subscribes to.
     *
     * @return list<string>
     */
    private function subscribedTypes(OutboxConsumer $consumer): array
    {
        $types = [];
        foreach ($this->container->make(EventCatalog::class)->all() as $type) {
            if ($consumer->subscribes($type)) {
                $types[] = $type->value;
            }
        }

        return $types;
    }

    private function gauges(CarbonImmutable $now): void
    {
        foreach ($this->store->oldestOpen($now) as $id => $createdAt) {
            $this->metrics->gauge(name: 'vaulter_outbox_lag_seconds', value: max(0, $now->getTimestamp() - $createdAt->getTimestamp()), labels: ['consumer' => $id]);
        }
    }

    private function owner(): string
    {
        return substr(gethostname() ?: 'relay', 0, 32).':'.getmypid().':'.Str::lower(Str::random(8));
    }
}
