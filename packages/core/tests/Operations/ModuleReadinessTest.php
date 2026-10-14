<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Vaulter\Doctor\Checks\SchemaTablesCheck;
use Vaulter\Exceptions\Modules\ModuleNotReadyException;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\ModuleReadiness;
use Vaulter\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Runs $callback and returns how many queries it ran. */
function readinessQueries(Closure $callback): int
{
    $queries = 0;
    Event::listen(QueryExecuted::class, static function () use (&$queries): void {
        $queries++;
    });
    $callback();

    return $queries;
}

it('queries nothing until the first write, once for it and never again for a ready module', function (): void {
    $readiness = null;

    expect(readinessQueries(static function () use (&$readiness): void {
        $readiness = app()->make(ModuleReadiness::class);
    }))->toBe(0)
        ->and(readinessQueries(static fn () => $readiness->assertReady(new ModuleId('drive'))))->toBe(1)
        ->and(readinessQueries(static fn () => $readiness->assertReady(new ModuleId('drive'))))->toBe(0)
        ->and(readinessQueries(static fn () => $readiness->assertReady(new ModuleId('media'))))->toBe(0)
        ->and(app(ModuleReadiness::class))->toBe(app(ModuleReadiness::class));
});

it('refuses a module whose tables are missing and checks it again on the next write', function (): void {
    $readiness = app()->make(ModuleReadiness::class);
    config()->set('vaulter.database.table_prefix', 'absent_');

    try {
        $readiness->assertReady(new ModuleId('drive'));
        $this->fail('A module without its tables was ready.');
    } catch (ModuleNotReadyException $refused) {
        expect($refused->problemCode())->toBe('module_not_ready')
            ->and($refused->httpStatus())->toBe(503)
            ->and($refused->getMessage())->toContain('absent_outbox');
    }

    config()->set('vaulter.database.table_prefix', 'v_');
    expect(readinessQueries(static fn () => $readiness->assertReady(new ModuleId('drive'))))->toBe(1);
});

it('refuses a module that is not active', function (): void {
    expect(fn () => app(ModuleReadiness::class)->assertReady(new ModuleId('absent')))
        ->toThrow(ModuleNotReadyException::class, 'not active');
});

it('splits the doctor tables by their owning module without losing one', function (): void {
    $doctor = (new ReflectionClassConstant(SchemaTablesCheck::class, 'ENTITIES'))->getValue();
    $owned = array_merge(...array_values(SchemaTablesCheck::OWNED_ENTITIES));
    sort($doctor);
    sort($owned);

    expect($owned)->toBe($doctor)
        ->and(SchemaTablesCheck::ownedBy('webhooks'))->toBe(['webhook_endpoints', 'webhook_deliveries'])
        ->and(SchemaTablesCheck::ownedBy('filament'))->toBe([]);
});
