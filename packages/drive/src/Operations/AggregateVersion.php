<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Support\Int64Counter;

/**
 * The own version of an aggregate that is not the node's content: `v_nodes.access_version` for the
 * grants and inheritance of a node, `v_node_shares.version`, `v_drives.version`. The caller holds the aggregate's
 * row lock inside the operation's transaction; the expected version from If-Match is compared there, and the write
 * raises it with one `UPDATE … SET <column> = <column> + 1 WHERE id = ? AND <column> = ?`. Any mismatch — before or
 * in the UPDATE — is `stale_write` (412); the HTTP pre-check only answers earlier.
 */
#[Internal]
final readonly class AggregateVersion
{
    public const string NODE_ACCESS = 'drive.node_access';

    public const string SHARE = 'drive.share';

    public const string DRIVE = 'drive.drive';

    public function __construct(private VaulterDatabase $database)
    {
        if (PHP_INT_SIZE !== 8) {
            throw new LogicException('Aggregate versions require a 64-bit PHP runtime.');
        }
    }

    /**
     * @param string $aggregate the aggregate of the operation catalogue the refusal names
     *
     * @throws StaleWriteException
     */
    public function assertExpected(Model $locked, string $column, ?int $expected, string $aggregate): void
    {
        $this->transaction();
        if ($expected !== null && $expected < 0) {
            throw new InvalidArgumentException('An expected version must be nonnegative.');
        }
        if ($expected !== null && self::current($locked, $column) !== $expected) {
            throw StaleWriteException::of($aggregate, self::id($locked));
        }
    }

    /**
     * Raise the version of the locked row by one: compare and set in one statement, so a write that slipped past
     * the lock (it cannot, but the statement does not rely on it) is refused instead of overwritten.
     *
     * @throws StaleWriteException
     *
     * @return int the new version
     */
    public function bump(Model $locked, string $column, string $aggregate): int
    {
        $this->transaction();
        $current = self::current($locked, $column);
        $next = Int64Counter::increment($current);
        // The base query: the version moves alone, without touching updated_at (an access change is not a node change).
        $updated = $locked->newQuery()->whereKey($locked->getKey())->where($column, $current)->toBase()->update([$column => $next]);
        if ($updated !== 1) {
            throw StaleWriteException::of($aggregate, self::id($locked));
        }
        $locked->forceFill([$column => $next])->syncOriginalAttribute($column);

        return $next;
    }

    private static function current(Model $locked, string $column): int
    {
        $value = $locked->getAttribute($column);

        return is_numeric($value) ? (int) $value : throw new LogicException(sprintf('[Vaulter] The locked %s row holds no version in "%s".', $locked->getTable(), $column));
    }

    private static function id(Model $locked): string
    {
        $key = $locked->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    private function transaction(): void
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('An aggregate version requires a locked row inside the Vaulter transaction.');
        }
    }
}
