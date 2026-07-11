<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Models\Drive\Node;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;

/**
 * F-P2.3-02 — `config('vaulter.connection')` parameterises WHERE Vaulter writes
 * its schema and data, so a host can embed the package's tables on a dedicated
 * database/connection (sharding, data placement, isolating a heavy CAS/activity
 * workload) without forking the package.
 *
 * Red-before: on the pre-fix code this test fails — `PanelTablesBuilder` always
 * writes through the default `Schema`/`DB` facades and every model's
 * `getConnectionName()` stays null regardless of `vaulter.connection`
 * (repro: `plans/AUDIT-VLT/artifacts/repro/P2.3/C6NoConnectionParamTest.php`).
 */
it('creates a panel\'s tables on the connection named by vaulter.connection, not the default one [F-P2.3-02]', function (): void {
    config()->set('database.connections.vaulter_other', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('vaulter.connection', 'vaulter_other');

    $builder = new PanelTablesBuilder;
    $panel = (new Panel)->id('conntest')->tablePrefix('conntest');

    $builder->createTable(panel: $panel, entity: 'storages');

    try {
        expect(Schema::connection('vaulter_other')->hasTable('conntest_storages'))->toBeTrue()
            ->and(Schema::hasTable('conntest_storages'))->toBeFalse();
    } finally {
        $builder->dropTable(panel: $panel, entity: 'storages');
    }
});

it('routes a default Vaulter model\'s connection through vaulter.connection [F-P2.3-02]', function (): void {
    config()->set('database.connections.vaulter_other', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('vaulter.connection', 'vaulter_other');

    expect((new Node)->getConnectionName())->toBe('vaulter_other');
});

it('control: vaulter.connection=null (the default) leaves every write on the host\'s default connection [F-P2.3-02]', function (): void {
    expect(config('vaulter.connection'))->toBeNull();

    $builder = new PanelTablesBuilder;
    $panel = (new Panel)->id('conntest')->tablePrefix('conntestctrl');

    $builder->createTable(panel: $panel, entity: 'storages');

    try {
        expect(Schema::hasTable('conntestctrl_storages'))->toBeTrue()
            ->and((new Node)->getConnectionName())->toBeNull();
    } finally {
        $builder->dropTable(panel: $panel, entity: 'storages');
    }
});
