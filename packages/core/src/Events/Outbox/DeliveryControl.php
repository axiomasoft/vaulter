<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox;

use Illuminate\Database\Query\Builder;
use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;

/**
 * The only way an {@see OutboxConsumer} changes a core table: its resume cursor on the delivery row it
 * holds, fenced by the lease generation the relay leased it with. A consumer that loses its lease (a takeover
 * after the lease expired) sees {@see saveContinuation()} return false and must stop: whatever it writes after
 * that is not accepted.
 *
 * Called inside the consumer's own transaction, the fenced UPDATE also locks the delivery row until that
 * transaction ends, so a takeover waits for the chunk to commit or roll back. {@see continuation()} answers the
 * last saved cursor of this attempt: a consumer whose transaction rolled back must give up the delivery (throw or
 * return a retry) rather than go on from it.
 */
#[Spi(SpiKind::Call)]
final class DeliveryControl
{
    public function __construct(
        private readonly VaulterDatabase $database,
        private readonly DatabaseConfig $tables,
        public readonly string $eventId,
        public readonly string $consumerId,
        public readonly int $generation,
        private ?string $continuation,
    ) {}

    /** The cursor the consumer saved on an earlier attempt of this delivery, or null. */
    public function continuation(): ?string
    {
        return $this->continuation;
    }

    /** Saves `$cursor` while this lease is current; false when the delivery was taken over. */
    public function saveContinuation(string $cursor): bool
    {
        $saved = $this->row()->update(['continuation' => $cursor, 'updated_at' => now()]) === 1;
        if ($saved) {
            $this->continuation = $cursor;
        }

        return $saved;
    }

    /** Whether the delivery is still leased under this generation. */
    public function isCurrent(): bool
    {
        return $this->row()->exists();
    }

    private function row(): Builder
    {
        return $this->database->connection()->table($this->tables->table(OutboxStore::DELIVERIES))
            ->where('event_id', $this->eventId)
            ->where('consumer_id', $this->consumerId)
            ->where('state', DeliveryState::Leased->value)
            ->where('lease_generation', $this->generation);
    }
}
