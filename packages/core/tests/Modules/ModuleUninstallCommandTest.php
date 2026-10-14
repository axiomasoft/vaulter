<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// D36: in P3 the command plans (dry run) and refuses to execute; P4 enables --force/--purge after the
// create-migrations are split by owner.

it('plans the removal of a module that ships no migration of its own', function (): void {
    $this->artisan('vaulter:module:uninstall', ['module' => 'webhooks'])
        ->expectsOutputToContain('none of its own')
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->assertSuccessful();
});

it('refuses the core: it cannot be uninstalled and other modules require it', function (): void {
    $this->artisan('vaulter:module:uninstall', ['module' => 'core'])
        ->expectsOutputToContain('2026_10_13_000001_create_v_drives_table')
        ->expectsOutputToContain('the core module cannot be uninstalled')
        ->expectsOutputToContain('module "webhooks" requires it')
        ->assertFailed();
});

it('refuses --force and --purge until the owner migrations are split (P4)', function (string $option): void {
    $this->artisan('vaulter:module:uninstall', ['module' => 'webhooks', $option => true])
        ->expectsOutputToContain('owner migrations are not split yet (P4)')
        ->assertFailed();
})->with(['--force', '--purge']);

it('refuses an unknown module', function (): void {
    $this->artisan('vaulter:module:uninstall', ['module' => 'nope'])
        ->expectsOutputToContain('No installed module is called "nope"')
        ->assertFailed();
});
