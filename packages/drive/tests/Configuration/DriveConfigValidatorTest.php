<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\CoreModule;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Configuration\DriveConfigValidator;
use Vaulter\Drive\DriveModule;
use Vaulter\Drive\Testing\Fixtures\Panels\OverrideDefaultProfile;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Media\MediaModule;
use Vaulter\Modules\ModuleCompiler;

function driveConfigRepository(): Repository
{
    return new Repository(['vaulter-drive' => require __DIR__.'/../../config/vaulter-drive.php']);
}

it('validates the shipped drive configuration', function (): void {
    $repository = driveConfigRepository();

    (new DriveConfigValidator($repository))->validate(new DriveConfig($repository), app(Application::class));

    expect($repository->get('vaulter-drive.access.permission_driver'))->toBeString();
});

it('fails boot for malformed security configuration', function (string $key, mixed $value): void {
    $repository = driveConfigRepository();
    $repository->set($key, $value);

    (new DriveConfigValidator($repository))->validate(new DriveConfig($repository), app(Application::class));
})->with([
    ['vaulter-drive.access.permission_driver', 'missing'],
    ['vaulter-drive.access.permission_driver', 42],
    ['vaulter-drive.access.permission_drivers.database', stdClass::class],
    ['vaulter-drive.sharing.token_generator', stdClass::class],
    ['vaulter-drive.models.node', stdClass::class],
    ['vaulter-drive.models.node', 42],
    ['vaulter-drive.profiles.providers', [stdClass::class]],
    ['vaulter-drive.profiles.default', stdClass::class],
    ['vaulter-drive.profiles.providers', [OverrideDefaultProfile::class]],
])->throws(InvalidConfigurationException::class);

it('rejects the null permission driver at production boot', function (): void {
    $repository = driveConfigRepository();
    $repository->set('vaulter-drive.access.permission_driver', 'null');
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('environment')->with('production')->andReturn(true);
    $kernel = new Kernel($application);
    $kernel->seal((new ModuleCompiler)->compile([new CoreModule, new MediaModule, new DriveModule], []));
    $application->shouldReceive('make')->with(Kernel::class)->andReturn($kernel);

    (new DriveConfigValidator($repository))->validate(new DriveConfig($repository), $application);
})->throws(InvalidConfigurationException::class, 'forbidden in production');

it('reports a malformed drive configuration under the doctor check of the kernel', function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'missing');

    $findings = app(ConfigValidCheck::class)->run(new DoctorContext(production: false, probeStorage: false, sampleLimit: 10, now: new DateTimeImmutable));

    expect(array_map(static fn ($finding): string => $finding->code, $findings))->toContain('core.config.invalid')
        ->and(json_encode(array_map(static fn ($finding): array => $finding->evidence, $findings)))->toContain('BindingsRegistrar');
});
