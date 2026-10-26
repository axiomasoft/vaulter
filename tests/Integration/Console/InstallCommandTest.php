<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Exception\InvalidOptionException;

beforeEach(function (): void {
    $this->files = new Filesystem;
    $this->published = [];
    foreach (['vaulter.php', 'vaulter-media.php', 'vaulter-drive.php', 'vaulter-documents.php'] as $name) {
        $this->published[] = config_path($name);
        $this->files->delete(config_path($name));
    }
});

afterEach(function (): void {
    foreach ($this->published as $path) {
        $this->files->delete($path);
    }
});

it('publishes the config of every installed package without prompting', function (): void {
    $this->artisan('vaulter:install', ['--no-interaction' => true])->assertExitCode(0);

    expect(config_path('vaulter.php'))->toBeFile()
        ->and(config_path('vaulter-media.php'))->toBeFile()
        ->and(config_path('vaulter-drive.php'))->toBeFile();
});

it('skips a config that is already published unless forced', function (): void {
    $this->files->put(config_path('vaulter.php'), '<?php return ["mine" => true];');

    $this->artisan('vaulter:install', ['--no-interaction' => true])->expectsOutputToContain('skipped')->assertExitCode(0);
    expect($this->files->get(config_path('vaulter.php')))->toContain('mine');

    $this->artisan('vaulter:install', ['--no-interaction' => true, '--force' => true])->assertExitCode(0);
    expect($this->files->get(config_path('vaulter.php')))->not->toContain('mine');
});

it('prints the host settings without editing the host config', function (): void {
    $this->artisan('vaulter:install', ['--no-interaction' => true, '--middleware' => 'auth:api'])
        ->expectsOutputToContain("'auth:api'")
        ->assertExitCode(0);

    expect($this->files->get(config_path('vaulter.php')))->not->toContain('auth:api');
});

it('falls back to the defaults non-interactively', function (): void {
    $this->artisan('vaulter:install', ['--no-interaction' => true])
        ->expectsOutputToContain("'auth:sanctum'")
        ->assertExitCode(0);
});

it('rejects a malformed middleware and no longer knows a permission driver option (the drive owns it)', function (): void {
    $this->artisan('vaulter:install', ['--no-interaction' => true, '--middleware' => 'auth sanctum'])->assertExitCode(1);
    expect(fn () => $this->artisan('vaulter:install', ['--no-interaction' => true, '--driver' => 'gate'])->run())->toThrow(InvalidOptionException::class, '--driver');
});

it('does not migrate without the flag', function (): void {
    $this->artisan('vaulter:install', ['--no-interaction' => true])->expectsOutputToContain('Migrations not run')->assertExitCode(0);
});

it('runs the migrations with --migrate', function (): void {
    $this->artisan('vaulter:install', ['--no-interaction' => true, '--migrate' => true])
        ->doesntExpectOutputToContain('Migrations not run')
        ->assertExitCode(0);
});

it('ends with the doctor report and still exits 0 when the doctor finds an error', function (): void {
    // The test host runs the null permission driver and has no auth middleware: the doctor reports errors.
    $this->artisan('vaulter:install', ['--no-interaction' => true])
        ->expectsOutputToContain('core.routes.auth')
        ->expectsOutputToContain('error')
        ->assertExitCode(0);
});

it('stays idempotent with the doctor step and leaves the published config as shipped', function (): void {
    $this->artisan('vaulter:install', ['--no-interaction' => true])->assertExitCode(0);
    $this->artisan('vaulter:install', ['--no-interaction' => true])->assertExitCode(0);

    expect(file_get_contents(config_path('vaulter.php')))->toBe(file_get_contents(dirname(__DIR__, 3).'/packages/core/config/vaulter.php'));
});
