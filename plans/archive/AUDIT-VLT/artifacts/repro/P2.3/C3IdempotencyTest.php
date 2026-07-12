<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;
use Vaulter\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * P2.3 / C3 — переживают ли миграции повторный прогон (rollback → remigrate),
 * т.е. down() -> up() -> down() -> up() без падения и без "хвостов" схемы.
 * Каждый файл — тонкая обёртка над PanelTablesBuilder::createTable()/dropTable(),
 * поэтому цикл гоняется напрямую по builder'у на репрезентативной сущности с FK
 * (nodes, зависит от storages) и на сущности с уникальными constraint'ами (blobs).
 */
it('nodes: down -> up -> down -> up переживает цикл без ошибок', function (): void {
    $builder = new PanelTablesBuilder;
    $panel = (new Panel)->id('default');

    // TestCase уже смигрировал схему (RefreshDatabase) — таблица существует.
    expect(Schema::hasTable('v_nodes'))->toBeTrue();

    $builder->dropTable(panel: $panel, entity: 'nodes');
    expect(Schema::hasTable('v_nodes'))->toBeFalse();

    // dropTable повторно на уже отсутствующей таблице — dropIfExists, не должен падать.
    $builder->dropTable(panel: $panel, entity: 'nodes');
    expect(Schema::hasTable('v_nodes'))->toBeFalse();

    $builder->createTable(panel: $panel, entity: 'nodes');
    expect(Schema::hasTable('v_nodes'))->toBeTrue();

    $builder->dropTable(panel: $panel, entity: 'nodes');
    $builder->createTable(panel: $panel, entity: 'nodes');
    expect(Schema::hasTable('v_nodes'))->toBeTrue();
});

it('blobs: цикл down -> up переживает уникальные dedup/gc индексы без коллизии имён', function (): void {
    $builder = new PanelTablesBuilder;
    $panel = (new Panel)->id('default');

    expect(Schema::hasTable('v_blobs'))->toBeTrue();

    $builder->dropTable(panel: $panel, entity: 'blobs');
    $builder->createTable(panel: $panel, entity: 'blobs');

    expect(Schema::hasTable('v_blobs'))->toBeTrue();

    $indexes = collect(Schema::getIndexes('v_blobs'))->pluck('name');
    expect($indexes)->toContain('v_blobs_dedup_uniq')
        ->and($indexes)->toContain('v_blobs_storage_key_uniq')
        ->and($indexes)->toContain('v_blobs_gc_idx');
});
