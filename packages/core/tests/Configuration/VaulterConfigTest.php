<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Configuration\VaulterConfig;
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

it('keeps no drive and no webhooks section in the kernel config root (P4.11, P4.13)', function (): void {
    /** @var array<string, mixed> $config */
    $config = require __DIR__.'/../../config/vaulter.php';

    expect(array_keys($config))->toBe(['database', 'ids', 'tenancy', 'security', 'modules', 'routes', 'outbox', 'idempotency', 'schedule', 'observability', 'models'])
        ->and(array_keys($config['models']))->toBe(['outbox'])
        ->and($config['schedule'])->not->toHaveKey('changes_prune')
        ->and(array_keys($config['schedule']))->toBe(['enabled', 'outbox_relay', 'outbox_prune', 'idempotency_prune']);
});

it('resolves the outbox model from vaulter.models and refuses a foreign class', function (): void {
    $repository = new Repository(['vaulter' => ['models' => ['outbox' => stdClass::class]]]);
    $config = new VaulterConfig($repository);

    expect(fn () => $config->outboxModel())->toThrow(InvalidConfigurationException::class, 'vaulter.models.outbox');

    $repository->set('vaulter.models.outbox', OutboxEvent::class);

    expect($config->outboxModel())->toBe(OutboxEvent::class);
});

it('has no accessor of a module section: the drive reads its own through DriveConfig', function (): void {
    foreach (['model', 'featureEnabled', 'scan', 'access', 'listing', 'sharing', 'downloads', 'changes'] as $method) {
        expect(method_exists(VaulterConfig::class, $method))->toBeFalse($method);
    }
});
