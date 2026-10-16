<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Drive\Schema\DriveSchema;
use Vaulter\Models\Drive\Node;

/**
 * F-P2.3-02 — `config('vaulter.database.connection')` parameterises WHERE Vaulter writes
 * its schema and data, so a host can embed the package's tables on a dedicated
 * database/connection (sharding, data placement, isolating a heavy CAS/activity
 * workload) without forking the package.
 *
 * Red-before: on the pre-fix code this test fails — the schema builder always
 * writes through the default `Schema`/`DB` facades and every model's
 * `getConnectionName()` stays null regardless of `vaulter.database.connection`
 * (repro: `plans/AUDIT-VLT/artifacts/repro/P2.3/C6NoConnectionParamTest.php`).
 */
it('creates a panel\'s tables on the connection named by vaulter.database.connection, not the default one [F-P2.3-02]', function (): void {
    config()->set('database.connections.vaulter_other', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('vaulter.database.connection', 'vaulter_other');

    $builder = new DriveSchema;
    config()->set('vaulter.database.table_prefix', 'conntest_');

    $builder->create(entity: 'drives');

    try {
        expect(Schema::connection('vaulter_other')->hasTable('conntest_drives'))->toBeTrue()
            ->and(Schema::hasTable('conntest_drives'))->toBeFalse();
    } finally {
        $builder->drop(entity: 'drives');
        config()->set('vaulter.database.connection', null);
        config()->set('vaulter.database.table_prefix', 'v_');
    }
});

it('routes a default Vaulter model\'s connection through vaulter.database.connection [F-P2.3-02]', function (): void {
    config()->set('database.connections.vaulter_other', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('vaulter.database.connection', 'vaulter_other');

    expect((new Node)->getConnectionName())->toBe('vaulter_other');
    config()->set('vaulter.database.connection', null);
});

it('control: vaulter.database.connection=null (the default) leaves every write on the host\'s default connection [F-P2.3-02]', function (): void {
    expect(config('vaulter.database.connection'))->toBeNull();

    $builder = new DriveSchema;
    config()->set('vaulter.database.table_prefix', 'conntestctrl_');

    $builder->create(entity: 'drives');

    try {
        expect(Schema::hasTable('conntestctrl_drives'))->toBeTrue()
            ->and((new Node)->getConnectionName())->toBeNull();
    } finally {
        $builder->drop(entity: 'drives');
        config()->set('vaulter.database.connection', null);
        config()->set('vaulter.database.table_prefix', 'v_');
    }
});
