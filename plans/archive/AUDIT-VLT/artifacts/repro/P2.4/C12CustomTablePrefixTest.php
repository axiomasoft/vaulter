<?php

declare(strict_types=1);

namespace Vaulter\Tests\P24Repro;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * C12 — установка с нестандартным table_prefix (не 'v_') не ломает миграции
 * и рантайм. Пересекается с P2.3 C5 (там — чтение PanelTablesBuilder), здесь
 * — сквозной прогон: смена префикса + реальный migrate().
 */
abstract class C12Base extends OrchestraTestCase
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            VaulterServiceProvider::class,
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
            VaulterDocumentsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Нестандартный префикс — "занятое" пространство имён хоста.
        $app['config']->set('vaulter.table_prefix', 'crm_vault_');
    }
}

uses(C12Base::class);

it('migrates cleanly with a non-standard table_prefix (C12)', function () {
    $exitCode = $this->artisan('migrate', ['--force' => true])->run();

    $tables = \Illuminate\Support\Facades\DB::select("select name from sqlite_master where type='table'");
    $tableNames = array_map(static fn ($row) => $row->name, $tables);

    dump('migrate exit code (custom prefix) = '.$exitCode);
    dump('sqlite tables with table_prefix=crm_vault_ = '.json_encode($tableNames, JSON_PRETTY_PRINT));

    expect($exitCode)->toBe(0);
    expect($tableNames)->toContain('crm_vault_nodes');
    expect($tableNames)->toContain('crm_vault_storages');
    expect($tableNames)->not->toContain('v_nodes');
    expect($tableNames)->not->toContain('v_storages');

    // Проверяем уникальный индекс дедупа (dedup.scope=tenant по умолчанию) —
    // имя индекса не коллидирует, реально создан под новым префиксом.
    $indexes = \Illuminate\Support\Facades\DB::select(
        "select name from sqlite_master where type='index' and tbl_name = 'crm_vault_blobs'"
    );
    dump('indexes on crm_vault_blobs = '.json_encode(array_map(static fn ($r) => $r->name, $indexes), JSON_PRETTY_PRINT));

    expect($indexes)->not->toBeEmpty();
});
