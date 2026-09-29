<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Load\Health\LoadReadinessRunner;

it('reports database probe failure when the connection is unreachable', function (): void {
    config([
        'database.default' => 'testing',
        'database.connections.testing' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'vaulter_unreachable',
            'username' => 'vaulter',
            'password' => 'secret',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
        'load.health.db_timeout_seconds' => 1,
    ]);

    DB::purge('testing');

    expect(app(LoadReadinessRunner::class)->checkDatabase())->toBeFalse();
});

it('bounds the database probe when the host does not accept connections', function (): void {
    config([
        'database.default' => 'testing',
        'database.connections.testing' => [
            'driver' => 'pgsql',
            'host' => '192.0.2.1',
            'port' => 5432,
            'database' => 'vaulter_blackhole',
            'username' => 'vaulter',
            'password' => 'secret',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'disable',
        ],
        'load.health.db_timeout_seconds' => 1,
    ]);

    DB::purge('testing');

    $startedAt = microtime(true);

    expect(app(LoadReadinessRunner::class)->checkDatabase())->toBeFalse();
    expect(microtime(true) - $startedAt)->toBeLessThan(3.0);
});
