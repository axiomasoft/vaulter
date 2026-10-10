<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Configuration\VaulterConfig;
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

it('keeps canonical table names separate from upgrade table overrides', function (): void {
    $config = new DatabaseConfig(new Repository(['vaulter' => ['database' => ['table_prefix' => 'custom_'], 'table_names' => ['storages' => 'old_drives']]]));
    expect($config->table('drives'))->toBe('custom_drives')
        ->and($config->legacyTable('storages'))->toBe('custom_old_drives');
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
