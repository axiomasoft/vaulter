<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Configuration\WebhooksConfig;
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
    ['vaulter.webhooks.delivery.url_guard', stdClass::class],
    ['vaulter.webhooks.relay.fanout_chunk', 0],
    ['vaulter.webhooks.relay.fanout_chunk', 1001],
    ['vaulter.webhooks.relay.fanout_chunk', 'all'],
    ['vaulter-drive.sharing.token_generator', stdClass::class],
    ['vaulter-drive.models.node', stdClass::class],
    ['vaulter-drive.models.node', 42],
    ['vaulter-drive.profiles.providers', [stdClass::class]],
    ['vaulter-drive.profiles.default', stdClass::class],
    ['vaulter-drive.profiles.providers', [OverrideDefaultProfile::class]],
    ['vaulter-drive.handlers', [static fn (): string => 'closure']],
])->throws(InvalidConfigurationException::class);

it('refuses a removed pre-1.0 webhook delivery key and names its replacement', function (string $key, string $replacement): void {
    $repository = new Repository(['vaulter' => require __DIR__.'/../../config/vaulter.php']);
    $repository->set('vaulter.webhooks.'.$key, 5);

    expect(fn () => (new ConfigValidator($repository))->validate(new VaulterConfig($repository), app(Application::class)))
        ->toThrow(InvalidConfigurationException::class, "vaulter.webhooks.{$key} was removed: use {$replacement}");
})->with([
    ['max_attempts', 'vaulter.webhooks.retry_schedule'],
    ['backoff', 'vaulter.webhooks.retry_schedule'],
    ['disable_after_failures', 'vaulter.webhooks.disable_after_days'],
    ['signature_header', 'the Standard Webhooks headers'],
    ['timestamp_header', 'the Standard Webhooks headers'],
    ['event_header', 'the event type'],
]);

it('rejects a malformed webhook retry policy', function (string $key, mixed $value): void {
    $repository = new Repository(['vaulter' => require __DIR__.'/../../config/vaulter.php']);
    $repository->set($key, $value);
    (new ConfigValidator($repository))->validate(new VaulterConfig($repository), app(Application::class));
})->with([
    ['vaulter.webhooks.retry_jitter', 0.9],
    ['vaulter.webhooks.retry_jitter', -0.1],
    ['vaulter.webhooks.retry_jitter', 'wide'],
    ['vaulter.webhooks.retry_schedule', [5, -1]],
    ['vaulter.webhooks.retry_schedule', [5, 'soon']],
])->throws(InvalidConfigurationException::class);

it('ships the 1.0 webhook delivery defaults', function (): void {
    $repository = new Repository(['vaulter' => require __DIR__.'/../../config/vaulter.php']);
    $webhooks = (new VaulterConfig($repository))->webhooks();

    expect($webhooks->timeout())->toBe(15)
        ->and($webhooks->retrySchedule())->toBe([5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400])
        ->and($webhooks->retryJitter())->toBe(0.1)
        ->and($webhooks->disableAfterDays())->toBe(5)
        ->and($webhooks->secretRotationOverlap())->toBe(86400)
        ->and($repository->has('vaulter.webhooks.max_attempts'))->toBeFalse();
});

it('caps the secret rotation overlap at seven days', function (): void {
    $repository = new Repository(['vaulter' => ['webhooks' => ['secret_rotation_overlap' => 9_999_999]]]);

    expect((new WebhooksConfig($repository))->secretRotationOverlap())->toBe(604800);
});

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
