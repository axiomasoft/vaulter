<?php

declare(strict_types=1);

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

it('returns 200 from /up without touching dependencies', function (): void {
    Event::fake([DiagnosingHealth::class]);

    config([
        'database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => '/path/that/does/not/exist/database.sqlite',
            'prefix' => '',
        ],
    ]);

    $this->get('/up')->assertOk();
    Event::assertDispatched(DiagnosingHealth::class);
});

it('returns ready when db s3 and queue probes succeed', function (): void {
    $response = $this->getJson('/ready');

    $response
        ->assertOk()
        ->assertJson([
            'status' => 'ready',
            'checks' => [
                'db' => ['ok' => true],
                's3' => ['ok' => true],
                'queue' => ['ok' => true],
            ],
        ]);
});

it('returns 503 with db failing when the connection is unreachable', function (): void {
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

    $response = $this->getJson('/ready');

    $response
        ->assertStatus(503)
        ->assertJson([
            'status' => 'not_ready',
            'checks' => [
                'db' => ['ok' => false],
                's3' => ['ok' => true],
                'queue' => ['ok' => true],
            ],
        ]);

    $this->get('/up')->assertOk();
});

it('returns 503 with s3 failing when the sentinel object is missing', function (): void {
    $sentinel = (string) config('load.health.s3_sentinel_key', '.vaulter-health-sentinel');
    Storage::disk('s3')->delete($sentinel);

    $response = $this->getJson('/ready');

    $response
        ->assertStatus(503)
        ->assertJson([
            'status' => 'not_ready',
            'checks' => [
                'db' => ['ok' => true],
                's3' => ['ok' => false],
                'queue' => ['ok' => true],
            ],
        ]);

    $this->get('/up')->assertOk();
});

it('returns 503 with queue failing when sync connection is used', function (): void {
    config(['queue.default' => 'sync']);

    $response = $this->getJson('/ready');

    $response
        ->assertStatus(503)
        ->assertJson([
            'status' => 'not_ready',
            'checks' => [
                'db' => ['ok' => true],
                's3' => ['ok' => true],
                'queue' => ['ok' => false],
            ],
        ]);

    $this->get('/up')->assertOk();
});
