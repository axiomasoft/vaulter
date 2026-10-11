<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Vaulter\Tests\TestCase;

uses(TestCase::class);

it('names every Vaulter command vaulter:<area>:<verb>', function (): void {
    // The one top-level entry point is the install command.
    $names = array_values(array_filter(array_keys(Artisan::all()), static fn (string $name): bool => str_starts_with($name, 'vaulter:') && $name !== 'vaulter:install'));

    expect($names)->not->toBeEmpty();
    foreach ($names as $name) {
        expect($name)->toMatch('/^vaulter:[a-z]+(-[a-z]+)*:[a-z]+(-[a-z]+)*$/');
    }
});

it('no longer registers the pre-0.3 command names', function (): void {
    expect(Artisan::all())->not->toHaveKeys([
        'vaulter:relay-outbox', 'vaulter:retry-webhooks', 'vaulter:prune-webhooks', 'vaulter:sweep-uploads',
        'vaulter:purge-trash', 'vaulter:scan-reconcile', 'vaulter:scan-rescan',
    ]);
});
