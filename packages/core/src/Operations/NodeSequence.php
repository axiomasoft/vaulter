<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use InvalidArgumentException;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Nodes\StaleNodeException;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Int64Counter;

#[Internal]
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
            throw StaleNodeException::forNode($locked->id);
        }
    }

    public function bump(Node $locked): int
    {
        $this->transaction();
        $current = $locked->sequence;
        $next = Int64Counter::increment($current);
        $updated = $locked->newQuery()->whereKey($locked->id)->where('drive_id', $locked->drive_id)->where('sequence', $current)->update(['sequence' => $next]);
        if ($updated !== 1) {
            throw StaleNodeException::forNode($locked->id);
        }
        $locked->forceFill(['sequence' => $next])->syncOriginalAttribute('sequence');

        return $next;
    }

    private function transaction(): void
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('Node sequence requires a locked node inside the Vaulter transaction.');
        }
    }
}
