<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Models\Node;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

it('validates canonical configuration defaults', function (): void {
    $repository = new Repository(['vaulter' => require __DIR__.'/../../../packages/core/config/vaulter.php']);
    (new ConfigValidator($repository))->validate(new VaulterConfig($repository), app(Application::class));
    expect(true)->toBeTrue();
});

it('fails boot for malformed security configuration', function (string $key, mixed $value): void {
    $repository = new Repository(['vaulter' => require __DIR__.'/../../../packages/core/config/vaulter.php']);
    $repository->set($key, $value);
    (new ConfigValidator($repository))->validate(new VaulterConfig($repository), app(Application::class));
})->with([
    ['vaulter.models.outbox', stdClass::class],
    ['vaulter.models.outbox', 42],
    ['vaulter.idempotency.ttl_seconds', 0],
    ['vaulter.idempotency.ttl_seconds', 'a day'],
    ['vaulter.features.webhooks', true],
    ['vaulter.routes.middleware.authenticated', [static fn (): string => 'closure']],
    ['vaulter-drive.handlers', [static fn (): string => 'closure']],
])->throws(InvalidConfigurationException::class);

it('leaves the sections of the modules to the modules', function (): void {
    // A malformed drive section is the drive's to refuse (DriveConfigValidator): the kernel validator does not read it.
    $repository = new Repository(['vaulter' => require __DIR__.'/../../../packages/core/config/vaulter.php']);
    $repository->set('vaulter-drive.access.permission_driver', 'missing');
    $repository->set('vaulter-drive.models.node', stdClass::class);

    (new ConfigValidator($repository))->validate(new VaulterConfig($repository), app(Application::class));

    expect(true)->toBeTrue();
});

it('validates module model subclass compatibility', function (): void {
    ConfigValidator::assertModel('module.models.node', Node::class, Node::class);
    ConfigValidator::assertModel('module.models.node', stdClass::class, Node::class);
})->throws(InvalidConfigurationException::class, 'must name a class');
