<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\CoreModule;
use Vaulter\Drive\DriveModule;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Fixtures\Panels\OverrideDefaultProfile;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Media\MediaModule;
use Vaulter\Modules\ModuleCompiler;

it('validates canonical configuration defaults', function (): void {
    $repository = new Repository(['vaulter' => require __DIR__.'/../../config/vaulter.php']);
    (new ConfigValidator($repository))->validate(new VaulterConfig($repository), app(Application::class));
    expect(true)->toBeTrue();
});

it('fails boot for malformed security configuration', function (string $key, mixed $value): void {
    $repository = new Repository(['vaulter' => require __DIR__.'/../../config/vaulter.php']);
    $repository->set($key, $value);
    (new ConfigValidator($repository))->validate(new VaulterConfig($repository), app(Application::class));
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
    ['vaulter-drive.handlers', [static fn (): string => 'closure']],
])->throws(InvalidConfigurationException::class);

it('rejects the null permission driver at production boot', function (): void {
    $repository = new Repository([
        'vaulter' => require __DIR__.'/../../config/vaulter.php',
        'vaulter-drive' => require __DIR__.'/../../../drive/config/vaulter-drive.php',
    ]);
    $repository->set('vaulter-drive.access.permission_driver', 'null');
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('environment')->with('production')->andReturn(true);
    // The drive sections are validated while the drive module is active.
    $kernel = new Kernel($application);
    $kernel->seal((new ModuleCompiler)->compile([new CoreModule, new MediaModule, new DriveModule], []));
    $application->shouldReceive('make')->with(Kernel::class)->andReturn($kernel);
    (new ConfigValidator($repository))->validate(new VaulterConfig($repository), $application);
})->throws(InvalidConfigurationException::class, 'forbidden in production');

it('validates module model subclass compatibility', function (): void {
    ConfigValidator::assertModel('module.models.node', Node::class, Node::class);
    ConfigValidator::assertModel('module.models.node', stdClass::class, Node::class);
})->throws(InvalidConfigurationException::class, 'must name a class');

it('rejects missing or reused collaboration secrets in production', function (?string $jwt, ?string $webhook): void {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('environment')->with('production')->andReturn(true);
    ConfigValidator::assertCollaborationSecrets(true, $jwt, $webhook, $application);
})->with([[null, null], ['same', 'same'], ['jwt', null], [null, 'webhook']])->throws(InvalidConfigurationException::class);
