<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Vaulter\CoreModule;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Configuration\DriveOptionsValidator;
use Vaulter\Drive\DriveModule;
use Vaulter\Drive\Security\PermissionDriverInvariant;
use Vaulter\Drive\Testing\Fixtures\Panels\OverrideDefaultProfile;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Media\MediaModule;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Modules\PluginModuleSpec;

function driveConfigRepository(): Repository
{
    return new Repository(['vaulter-drive' => require __DIR__.'/../../config/vaulter-drive.php']);
}

it('validates the shipped drive configuration', function (): void {
    $repository = driveConfigRepository();
    $options = DriveOptions::fromArray((array) $repository->get('vaulter-drive', []));

    new DriveOptionsValidator($repository)->validate($options, app(Application::class));

    expect($options->access->permissionDriver)->toBeString();
});

it('fails boot for malformed structural security configuration', function (string $key, mixed $value): void {
    $repository = driveConfigRepository();
    $repository->set($key, $value);

    DriveOptions::fromArray((array) $repository->get('vaulter-drive', []));
})->with([
    ['vaulter-drive.access.permission_driver', 42],
    ['vaulter-drive.sharing.token_generator', stdClass::class],
    ['vaulter-drive.models.node', stdClass::class],
    ['vaulter-drive.models.node', 42],
    ['vaulter-drive.profiles.providers', [stdClass::class]],
    ['vaulter-drive.profiles.default', stdClass::class],
])->throws(InvalidConfiguration::class);

it('fails boot for malformed semantic security configuration', function (string $key, mixed $value): void {
    $repository = driveConfigRepository();
    $repository->set($key, $value);
    $options = DriveOptions::fromArray((array) $repository->get('vaulter-drive', []));

    new DriveOptionsValidator($repository)->validate($options, app(Application::class));
})->with([
    ['vaulter-drive.access.permission_driver', 'missing'],
    ['vaulter-drive.access.permission_drivers.database', stdClass::class],
    ['vaulter-drive.profiles.providers', [OverrideDefaultProfile::class]],
])->throws(InvalidConfigurationException::class);

it('reports the null permission driver as the drive.permission-driver invariant, not as a structural error', function (): void {
    $repository = driveConfigRepository();
    $repository->set('vaulter-drive.access.permission_driver', 'null');
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('resolved')->andReturn(false);
    $kernel = new Kernel($application);
    $kernel->seal((new ModuleCompiler)->compile([], [], [
        new PluginModuleSpec(CoreModule::class),
        new PluginModuleSpec(MediaModule::class),
        new PluginModuleSpec(DriveModule::class),
    ]));
    $application->shouldReceive('make')->with(Kernel::class)->andReturn($kernel);
    $application->shouldReceive('environment')->andReturn(true);

    $options = DriveOptions::fromArray((array) $repository->get('vaulter-drive', []));
    $invariant = new PermissionDriverInvariant($options, $application);

    expect($invariant->id())->toBe('drive.permission-driver')
        ->and($invariant->violation())->toContain('"null"')
        ->and(fn () => new DriveOptionsValidator($repository)->validate($options, $application))->not->toThrow(InvalidConfigurationException::class);
});

it('reports a malformed drive configuration under the doctor check of the kernel', function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'missing');

    $findings = app(ConfigValidCheck::class)->run(new DoctorContext(production: false, probeStorage: false, sampleLimit: 10, now: new DateTimeImmutable));

    expect(array_map(static fn (DoctorFinding $finding): string => $finding->code, $findings))->toContain('core.config.invalid')
        ->and(json_encode(array_map(static fn (DoctorFinding $finding): array => $finding->evidence, $findings)))->toContain('BindingsRegistrar');
});
