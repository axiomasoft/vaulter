<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Vaulter\Models\Webhooks\Outbox;

uses(RefreshDatabase::class);

function lastChaosJson(): array
{
    $lines = array_values(array_filter(explode("\n", Artisan::output())));

    return json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
}

it('measures bytes persisted by the real CAS staging path', function (): void {
    expect(Artisan::call('load:chaos-operation', [
        'operation' => 'cas-stage',
        '--run-prefix' => 'p7-cas-stage',
        '--bytes' => 8192,
        '--ordinal' => 1,
    ]))->toBe(0);

    $result = lastChaosJson();

    expect($result['operation'])->toBe('cas-stage')
        ->and($result['requested_bytes'])->toBe(8192)
        ->and($result['written_bytes'])->toBe(8192)
        ->and($result['entity_id'])->toHaveLength(64)
        ->and($result['error_class'])->toBeNull();
});

it('measures a bounded write and keeps it inside the dedicated chaos root', function (): void {
    $prefix = 'p7-write-'.bin2hex(random_bytes(4));
    $path = "/var/tmp/vaulter-chaos/{$prefix}-write-2.bin";

    try {
        expect(Artisan::call('load:chaos-operation', [
            'operation' => 'write',
            '--run-prefix' => $prefix,
            '--bytes' => 4096,
            '--ordinal' => 2,
        ]))->toBe(0);

        $result = lastChaosJson();

        expect($result['requested_bytes'])->toBe(4096)
            ->and($result['written_bytes'])->toBe(4096)
            ->and($result['entity_id'])->toBe($path)
            ->and(filesize($result['entity_id']))->toBe(4096);
    } finally {
        File::delete($path);
    }
});

it('holds the database seam while staging a run-scoped outbox mutation', function (): void {
    $prefix = 'p7-lock-'.bin2hex(random_bytes(4));

    $exit = Artisan::call('load:chaos-operation', [
        'operation' => 'lock-holder',
        '--run-prefix' => $prefix,
        '--bytes' => 1,
        '--ordinal' => 1,
    ]);

    $result = lastChaosJson();

    expect($exit)->toBe(0, json_encode($result, JSON_THROW_ON_ERROR))
        ->and($result['entity_id'])->toBe("{$prefix}:lock-holder:1")
        ->and(Outbox::query()->where('event_id', $result['entity_id'])->exists())->toBeTrue();
});
