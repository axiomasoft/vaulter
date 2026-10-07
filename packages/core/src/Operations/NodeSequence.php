<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use InvalidArgumentException;
use LogicException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Nodes\SequenceOverflowException;
use Vaulter\Exceptions\Nodes\StaleNodeException;
use Vaulter\Models\Drive\Node;

final readonly class NodeSequence
{
    public function __construct(private VaulterDatabase $database)
    {
        if (PHP_INT_SIZE !== 8) {
            throw new LogicException('Node sequence requires a 64-bit PHP runtime.');
        }
    }

    public function assertExpected(Node $locked, ?int $expected): void
    {
        $this->transaction();
        if ($expected !== null && $expected < 0) {
            throw new InvalidArgumentException('Expected sequence must be nonnegative.');
        }
        if ($expected !== null && $locked->sequence !== $expected) {
            throw new StaleNodeException;
        }
    }

    public function bump(Node $locked): int
    {
        $this->transaction();
        $current = $locked->sequence;
        if ($current < 0 || $current === PHP_INT_MAX) {
            throw new SequenceOverflowException;
        }
        $updated = $locked->newQuery()->whereKey($locked->id)->where('drive_id', $locked->drive_id)->where('sequence', $current)->update(['sequence' => $current + 1]);
        if ($updated !== 1) {
            throw new StaleNodeException;
        }
        $locked->forceFill(['sequence' => $current + 1])->syncOriginalAttribute('sequence');

        return $current + 1;
    }

    private function transaction(): void
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('Node sequence requires a locked node inside the Vaulter transaction.');
        }
    }
}
