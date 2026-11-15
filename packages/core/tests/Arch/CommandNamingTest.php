<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Vaulter\Tests\TestCase;

uses(TestCase::class);

it('names every Vaulter command vaulter:<area>:<verb>', function (): void {
    // The top-level entry points are the install, doctor and modules commands (the module listing) and the outbox
    // relay with its replay (vaulter:relay and vaulter:relay:replay) and the operation catalogue (named
    // vaulter:operations).
    $names = array_values(array_filter(array_keys(Artisan::all()), static fn (string $name): bool => str_starts_with($name, 'vaulter:') && !in_array($name, ['vaulter:install', 'vaulter:doctor', 'vaulter:modules', 'vaulter:relay', 'vaulter:operations'], true)));

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
