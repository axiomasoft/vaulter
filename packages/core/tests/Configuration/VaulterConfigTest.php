<?php

declare(strict_types=1);

use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Configuration\RoutesOptions;
use Vaulter\Configuration\VaulterOptions;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Http\Middleware\AddApiVersionHeader;
use Vaulter\Http\Middleware\ApplyResponseCachePolicy;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Http\Middleware\RecordVaulterRequestMetrics;

it('constructs immutable options from config arrays', function (): void {
    $first = VaulterOptions::fromArray(['database' => ['connection' => 'first']]);
    $second = VaulterOptions::fromArray(['database' => ['connection' => 'second']]);

    expect($first->database->connection)->toBe('first')
        ->and($second->database->connection)->toBe('second');
});

it('names every table by the configured prefix and rejects unknown keys on closed schemas', function (): void {
    $options = DatabaseOptions::fromArray(['table_prefix' => 'custom_']);
    expect($options->table('drives'))->toBe('custom_drives');

    expect(fn (): VaulterOptions => VaulterOptions::fromArray(['database' => ['table_prefix' => 'custom_'], 'table_names' => ['drives' => 'old_drives']]))
        ->toThrow(InvalidConfiguration::class, 'vaulter.table_names');
});

it('adds the package middleware exactly once to each route middleware stack', function (): void {
    $defaults = RoutesOptions::fromArray([]);
    $package = [AssignCorrelationId::class, AddApiVersionHeader::class, ApplyResponseCachePolicy::class, RecordVaulterRequestMetrics::class];
    expect($defaults->authenticatedMiddleware())->toBe(['api', 'auth:sanctum', ...$package]);

    $custom = RoutesOptions::fromArray(['middleware' => ['public' => [RecordVaulterRequestMetrics::class]]]);
    expect($custom->publicMiddleware())->toBe([RecordVaulterRequestMetrics::class, AssignCorrelationId::class, AddApiVersionHeader::class, ApplyResponseCachePolicy::class])
        ->and($custom->versionedPrefix())->toBe('api/vaulter/v1');
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
    expect(fn (): VaulterOptions => VaulterOptions::fromArray(['models' => ['outbox' => stdClass::class]]))
        ->toThrow(InvalidConfiguration::class, 'vaulter.models.outbox');

    $options = VaulterOptions::fromArray(['models' => ['outbox' => OutboxEvent::class]]);

    expect($options->outboxModel())->toBe(OutboxEvent::class);
});

it('has no accessor of a module section: the drive reads its own through DriveOptions', function (): void {
    foreach (['model', 'featureEnabled', 'scan', 'access', 'listing', 'sharing', 'downloads', 'changes'] as $method) {
        expect(method_exists(VaulterOptions::class, $method))->toBeFalse($method);
    }
});
