<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Tests\Modules\Fixtures\FixtureModulesProvider;
use Vaulter\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// D36: in P3 the command plans (dry run) and refuses to execute; P4.17 enables --force/--purge on top of the
// create-migrations that P4.5 split by owner.

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

it('refuses --force and --purge until the owner migrations are split (P4)', function (string $option): void {
    $this->artisan('vaulter:module:uninstall', ['module' => 'webhooks', $option => true])
        ->expectsOutputToContain('the uninstall of the owner migrations is not available yet (P4)')
        ->assertFailed();
})->with(['--force', '--purge']);

it('refuses an unknown module', function (): void {
    $this->artisan('vaulter:module:uninstall', ['module' => 'nope'])
        ->expectsOutputToContain('No installed module is called "nope"')
        ->assertFailed();
});
