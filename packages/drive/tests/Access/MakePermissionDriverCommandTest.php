<?php

declare(strict_types=1);

use App\Vaulter\PermissionDrivers\Workspace;
use Illuminate\Support\Facades\File;
use Vaulter\Testing\Contracts\PermissionDriverContractTests;

beforeEach(function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    File::deleteDirectory(app_path('Vaulter'));
    File::deleteDirectory(base_path('tests/Unit/Vaulter'));
});

afterEach(function (): void {
    File::deleteDirectory(app_path('Vaulter'));
    File::deleteDirectory(base_path('tests/Unit/Vaulter'));
});

it('scaffolds a driver that decides like the reference', function (): void {
    $accessBefore = config('vaulter.access');

    $this->artisan('vaulter:make:permission-driver', ['name' => 'Workspace'])
        ->expectsOutputToContain("'workspace' => \\App\\Vaulter\\PermissionDrivers\\Workspace::class,")
        ->assertSuccessful();

    $class = app_path('Vaulter/PermissionDrivers/Workspace.php');
    $test = base_path('tests/Unit/Vaulter/WorkspaceTest.php');
    expect($class)->toBeFile()->and($test)->toBeFile();
    foreach ([$class, $test] as $path) {
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $output, $code);
        expect($code)->toBe(0);
    }
    expect(File::get($test))->toContain('PermissionDriverContractTests')
        ->and(config('vaulter.access'))->toBe($accessBefore);

    require_once $class;
    (new PermissionDriverContractTests)->assertSameDecisionsAsReference(new Workspace);
});

it('refuses to overwrite without --force and overwrites with it', function (): void {
    $this->artisan('vaulter:make:permission-driver', ['name' => 'Workspace'])->assertSuccessful();
    $this->artisan('vaulter:make:permission-driver', ['name' => 'Workspace'])->assertFailed();
    $this->artisan('vaulter:make:permission-driver', ['name' => 'Workspace', '--force' => true])->assertSuccessful();
});
