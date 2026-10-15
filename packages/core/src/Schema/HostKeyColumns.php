<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Configuration\IdsConfig;

#[Api]
final class HostKeyColumns
{
    public static function add(Blueprint $table, string $column, bool $nullable, bool $index): void
    {
        $definition = match (app(IdsConfig::class)->hostKeys()) {
            'string' => $table->string($column, 64),
            'bigint' => $table->unsignedBigInteger($column),
            'uuid' => $table->uuid($column),
            'ulid' => $table->char($column, 26),
        };

        $definition->nullable($nullable);

        if ($index) {
            $definition->index();
        }
    }

    public static function sqlType(string $driver): string
    {
        if (!in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException('[Vaulter] Unsupported host-key SQL driver ['.$driver.'].');
        }

        return match (app(IdsConfig::class)->hostKeys()) {
            'string' => 'varchar(64)',
            'bigint' => $driver === 'pgsql' ? 'bigint' : 'bigint unsigned',
            'uuid' => $driver === 'pgsql' ? 'uuid' : 'char(36)',
            'ulid' => 'char(26)',
        };
    }
}
