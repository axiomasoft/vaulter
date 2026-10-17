<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Models\Node;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Http\Middleware\AddApiVersionHeader;
use Vaulter\Http\Middleware\ApplyResponseCachePolicy;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Http\Middleware\RecordVaulterRequestMetrics;

it('reads repository changes without caching values', function (): void {
    $repository = new Repository(['vaulter' => ['database' => ['connection' => 'first']]]);
    $config = new VaulterConfig($repository);
    expect($config->database()->connection())->toBe('first');
    $repository->set('vaulter.database.connection', 'second');
    expect($config->database()->connection())->toBe('second');
});

it('names every table by the configured prefix and the canonical entity, ignoring table_names', function (): void {
    $config = new DatabaseConfig(new Repository(['vaulter' => ['database' => ['table_prefix' => 'custom_'], 'table_names' => ['drives' => 'old_drives']]]));
    expect($config->table('drives'))->toBe('custom_drives');
});

it('adds the package middleware exactly once to each route middleware stack', function (): void {
    $repository = new Repository;
    $config = new RoutesConfig($repository);
    $package = [AssignCorrelationId::class, AddApiVersionHeader::class, ApplyResponseCachePolicy::class, RecordVaulterRequestMetrics::class];
    expect($config->authenticatedMiddleware())->toBe(['api', 'auth:sanctum', ...$package]);
    $repository->set('vaulter.routes.middleware.public', [RecordVaulterRequestMetrics::class]);
    expect($config->publicMiddleware())->toBe([RecordVaulterRequestMetrics::class, AssignCorrelationId::class, AddApiVersionHeader::class, ApplyResponseCachePolicy::class])
        ->and($config->versionedPrefix())->toBe('api/vaulter/v1');
});

it('keeps no drive section in the kernel config root (P4.11)', function (): void {
    /** @var array<string, mixed> $config */
    $config = require __DIR__.'/../../config/vaulter.php';

    expect(array_keys($config))->toBe(['database', 'ids', 'tenancy', 'modules', 'routes', 'webhooks', 'outbox', 'idempotency', 'schedule', 'observability', 'models'])
        ->and(array_keys($config['models']))->toBe(['outbox', 'webhook_endpoint', 'webhook_delivery'])
        ->and($config['schedule'])->not->toHaveKey('changes_prune');
});

it('resolves the model keys of the core from vaulter.models and those of the drive from vaulter-drive.models', function (): void {
    $repository = new Repository([
        'vaulter' => ['models' => ['outbox' => stdClass::class]],
        'vaulter-drive' => ['models' => ['node' => stdClass::class]],
    ]);
    $config = new VaulterConfig($repository);

    expect(fn () => $config->model('outbox'))->toThrow(InvalidConfigurationException::class, 'vaulter.models.outbox')
        ->and(fn () => $config->model('node'))->toThrow(InvalidConfigurationException::class, 'vaulter-drive.models.node')
        ->and(fn () => $config->model('nope'))->toThrow(InvalidConfigurationException::class, 'vaulter-drive.models.nope');

    $repository->set('vaulter.models.outbox', OutboxEvent::class);
    $repository->set('vaulter-drive.models.node', Node::class);

    expect($config->model('outbox'))->toBe(OutboxEvent::class)
        ->and($config->model('node'))->toBe(Node::class)
        ->and($config->featureEnabled('changes'))->toBeFalse();

    $repository->set('vaulter-drive.features.changes', true);

    expect($config->featureEnabled('changes'))->toBeTrue();
});
