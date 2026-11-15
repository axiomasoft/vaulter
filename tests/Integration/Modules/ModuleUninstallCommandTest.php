<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Integration\Tests\TestCase;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Tests\Modules\Fixtures\FixtureModulesProvider;

uses(TestCase::class, RefreshDatabase::class);

// The plan of the command (a dry run); its execution with --force/--purge is ModuleUninstallTest.

it('plans the removal of a module that ships its migrations in its own package', function (): void {
    $this->artisan('vaulter:module:uninstall', ['module' => 'webhooks'])
        ->expectsOutputToContain('0001_01_01_000401_create_v_webhook_endpoints_table')
        ->expectsOutputToContain('v_webhook_deliveries')
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->assertSuccessful();
});

it('plans the removal of a module that ships no migration of its own', function (): void {
    $console = FreshApplication::boot(after: [FixtureModulesProvider::class])->make(ConsoleKernel::class);

    expect($console->call('vaulter:module:uninstall', ['module' => 'plugin']))->toBe(0)
        ->and($console->output())->toContain('none of its own')->toContain('Dry run: nothing was changed.');
});

it('refuses the core: it cannot be uninstalled and other modules require it', function (): void {
    $this->artisan('vaulter:module:uninstall', ['module' => 'core'])
        ->expectsOutputToContain('0001_01_01_000001_create_v_outbox_table')
        ->expectsOutputToContain('the core module cannot be uninstalled')
        ->expectsOutputToContain('module "webhooks" requires it')
        ->assertFailed();
});

it('refuses an unknown module as a usage error', function (): void {
    $this->artisan('vaulter:module:uninstall', ['module' => 'nope'])
        ->expectsOutputToContain('No installed module is called "nope"')
        ->assertExitCode(2);
});
