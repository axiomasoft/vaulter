<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\GuardedModule;

use RuntimeException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Modules\RemovalGuard;

/**
 * The removal guard of the {@see GuardedModule}, scripted by a test: each read answers the next entry of `$script`
 * (the last one repeats) and records the transaction level it was read at. `$broken` makes the container fail to build it.
 */
final class GuardedRemovalGuard implements RemovalGuard
{
    /** @var list<array<string, int>> the answers of the reads, in order */
    public static array $script = [[]];

    public static bool $broken = false;

    /** @var list<array{module: string, transactionLevel: int}> */
    public static array $reads = [];

    public function __construct(private readonly VaulterDatabase $database)
    {
        if (self::$broken) {
            throw new RuntimeException('the guard store is offline');
        }
    }

    /** @param list<array<string, int>> $script */
    public static function reset(array $script = [[]]): void
    {
        self::$script = $script;
        self::$broken = false;
        self::$reads = [];
    }

    public function blockers(string $module): array
    {
        $read = count(self::$reads);
        self::$reads[] = ['module' => $module, 'transactionLevel' => $this->database->connection()->transactionLevel()];
        if ($module !== 'guarded') {
            return [];
        }

        return self::$script[min($read, count(self::$script) - 1)] ?? [];
    }
}
