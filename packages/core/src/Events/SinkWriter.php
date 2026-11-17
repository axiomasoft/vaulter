<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Vaulter\Database\VaulterDatabase;

/**
 * What an {@see EventSink} writes with: the Vaulter connection inside the transaction of the operation that
 * records the event. It opens neither a transaction nor a connection. One writer serves the sinks and the
 * outbox of one event, so a sink can hand a value to the ones after it — the drive's change journal shares
 * its seq, which the outbox envelope carries.
 */
#[Spi(SpiKind::Call)]
final class SinkWriter
{
    /** The change-journal seq of the event, shared by the drive's journal sink. */
    public const string JOURNAL_SEQ = 'journal_seq';

    /** @var array<string, int|string|null> */
    private array $shared = [];

    public function __construct(private readonly VaulterDatabase $database) {}

    public function connection(): Connection
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('[Vaulter] An event sink writes only inside the transaction of the operation.');
        }

        return $this->database->connection();
    }

    /**
     * Insert `$row` unless a row with the same `$uniqueColumn` value exists; true when this call inserted it.
     *
     * @param array<string, mixed> $row column => database-ready value
     */
    public function insertOnce(string $table, array $row, string $uniqueColumn): bool
    {
        $this->connection();

        return $this->database->insertOnce($table, $row, $uniqueColumn);
    }

    /** Hands `$value` to the sinks and the outbox that write this event after the caller. */
    public function share(string $key, int|string|null $value): void
    {
        $this->shared[$key] = $value;
    }

    public function shared(string $key): int|string|null
    {
        return $this->shared[$key] ?? null;
    }
}
