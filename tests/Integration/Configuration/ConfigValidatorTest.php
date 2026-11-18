<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Configuration\VaulterOptions;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Models\Node;

it('validates canonical configuration defaults', function (): void {
    $repository = new Repository(['vaulter' => require __DIR__.'/../../../packages/core/config/vaulter.php']);
    VaulterOptions::fromArray($repository->get('vaulter'));
    expect(true)->toBeTrue();
});

it('fails boot for malformed security configuration', function (string $key, mixed $value): void {
    $repository = new Repository(['vaulter' => require __DIR__.'/../../../packages/core/config/vaulter.php']);
    $repository->set($key, $value);
    VaulterOptions::fromArray($repository->get('vaulter'));
})->with([
    ['vaulter.models.outbox', stdClass::class],
    ['vaulter.models.outbox', 42],
    ['vaulter.idempotency.ttl_seconds', 0],
    ['vaulter.idempotency.ttl_seconds', 'a day'],
    ['vaulter.features.webhooks', true],
    ['vaulter.routes.middleware.authenticated', [static fn (): string => 'closure']],
])->throws(InvalidConfiguration::class);

it('leaves the sections of the modules to the modules', function (): void {
    // A malformed drive section is the drive's to refuse (DriveConfigValidator): the kernel validator does not read it.
    $repository = new Repository(['vaulter' => require __DIR__.'/../../../packages/core/config/vaulter.php']);
    $repository->set('vaulter-drive.access.permission_driver', 'missing');
    $repository->set('vaulter-drive.models.node', stdClass::class);

    VaulterOptions::fromArray($repository->get('vaulter'));

    expect(true)->toBeTrue();
});

it('validates module model subclass compatibility', function (): void {
    DriveOptions::fromArray(['models' => ['node' => Node::class]]);
    DriveOptions::fromArray(['models' => ['node' => stdClass::class]]);
})->throws(InvalidConfiguration::class);
