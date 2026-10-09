<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Fixtures\Panels\OverrideDefaultProfile;

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
    ['vaulter.access.permission_driver', 'missing'],
    ['vaulter.access.permission_driver', 42],
    ['vaulter.access.permission_drivers.database', stdClass::class],
    ['vaulter.webhooks.delivery.url_guard', stdClass::class],
    ['vaulter.webhooks.relay.fanout_chunk', 0],
    ['vaulter.webhooks.relay.fanout_chunk', 1001],
    ['vaulter.webhooks.relay.fanout_chunk', 'all'],
    ['vaulter.sharing.token_generator', stdClass::class],
    ['vaulter.models.node', stdClass::class],
    ['vaulter.models.node', 42],
    ['vaulter.profiles.providers', [stdClass::class]],
    ['vaulter.profiles.default', stdClass::class],
    ['vaulter.profiles.providers', [OverrideDefaultProfile::class]],
    ['vaulter.handlers', [static fn (): string => 'closure']],
])->throws(InvalidConfigurationException::class);

it('rejects the null permission driver at production boot', function (): void {
    $repository = new Repository(['vaulter' => require __DIR__.'/../../config/vaulter.php']);
    $repository->set('vaulter.access.permission_driver', 'null');
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('environment')->with('production')->andReturn(true);
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
