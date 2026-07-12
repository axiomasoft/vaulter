<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;
use Vaulter\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * P2.3 / C5 — префикс реально применяется ко ВСЕМ таблицам панели и к именам
 * индексов/констрейнтов, деривясь из имени таблицы (PanelTablesBuilder: `$name =
 * $table->getTable();` -> "{$name}_dedup_uniq" и т.д.). Repro: две own-tables
 * панели с РАЗНЫМИ префиксами мигрируют бок о бок — если бы имя индекса было
 * захардкожено (не деривировано из таблицы), вторая панель упала бы на
 * "index already exists".
 */
it('две own-tables панели с разными префиксами не коллизируют по именам индексов', function (): void {
    $builder = new PanelTablesBuilder;

    $panelA = (new Panel)->id('alpha')->tablePrefix('alpha_pfx');
    $panelB = (new Panel)->id('beta')->tablePrefix('beta_pfx');

    // storages/nodes/blobs достаточно: несут afterCreate()-хуки с driver-aware
    // индексами (addOwnerUniqueIndex, addSiblingUniqueIndex) — самое опасное место
    // для коллизии между панелями.
    foreach (['storages', 'nodes', 'blobs'] as $entity) {
        $builder->createTable(panel: $panelA, entity: $entity);
        $builder->createTable(panel: $panelB, entity: $entity);
    }

    expect(Schema::hasTable('alpha_pfx_storages'))->toBeTrue()
        ->and(Schema::hasTable('beta_pfx_storages'))->toBeTrue()
        ->and(Schema::hasTable('alpha_pfx_nodes'))->toBeTrue()
        ->and(Schema::hasTable('beta_pfx_nodes'))->toBeTrue()
        ->and(Schema::hasTable('alpha_pfx_blobs'))->toBeTrue()
        ->and(Schema::hasTable('beta_pfx_blobs'))->toBeTrue();

    // 'primary' — синтетическая метка PK без явного имени (Blueprint не называет
    // первичный ключ), одинакова НА КАЖДОЙ таблице по конструкции SQLite/Laravel
    // introspection; это не дериват от имени таблицы и не является тем именованным
    // индексом, который проверяет C5 — исключается из сравнения на коллизию.
    $rejectPrimary = fn (string $name): bool => $name === 'primary';

    $alphaNodeIdx = collect(Schema::getIndexes('alpha_pfx_nodes'))->pluck('name')->reject($rejectPrimary);
    $betaNodeIdx = collect(Schema::getIndexes('beta_pfx_nodes'))->pluck('name')->reject($rejectPrimary);

    expect($alphaNodeIdx)->toContain('alpha_pfx_nodes_sibling_uniq')
        ->and($betaNodeIdx)->toContain('beta_pfx_nodes_sibling_uniq')
        // Ни одно имя индекса не пересекается между панелями — по конструкции
        // (дериват из полного имени таблицы), а не случайно.
        ->and($alphaNodeIdx->intersect($betaNodeIdx))->toBeEmpty();

    $alphaBlobIdx = collect(Schema::getIndexes('alpha_pfx_blobs'))->pluck('name')->reject($rejectPrimary);
    $betaBlobIdx = collect(Schema::getIndexes('beta_pfx_blobs'))->pluck('name')->reject($rejectPrimary);

    expect($alphaBlobIdx->intersect($betaBlobIdx))->toBeEmpty();

    $builder->dropTable(panel: $panelA, entity: 'nodes');
    $builder->dropTable(panel: $panelA, entity: 'blobs');
    $builder->dropTable(panel: $panelA, entity: 'storages');
    $builder->dropTable(panel: $panelB, entity: 'nodes');
    $builder->dropTable(panel: $panelB, entity: 'blobs');
    $builder->dropTable(panel: $panelB, entity: 'storages');
});
