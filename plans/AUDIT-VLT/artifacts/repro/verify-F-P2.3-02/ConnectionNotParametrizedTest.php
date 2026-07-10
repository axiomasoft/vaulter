<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;

/**
 * Скептик-верификация F-P2.3-02 («connection не параметризован», E2.3).
 * Цель — попытаться ОПРОВЕРГНУТЬ находку: показать, что где-то выше по стеку
 * (Panel, PanelTablesBuilder, конфиг, модели) соединение БД всё-таки читается
 * из config('vaulter.connection') или эквивалента, и таблицы можно вынести на
 * отдельное соединение без форка пакета.
 *
 * Результат — находка НЕ опровергнута: PanelTablesBuilder::createTable() везде
 * пишет через дефолтный Schema-фасад (packages/core/src/Schema/PanelTablesBuilder.php:98,
 * :127, :902, :917, :933, :969, :985 и т.д. — ни один вызов не проходит через
 * Schema::connection(...)), у Panel нет метода connection()/connectionName()
 * (packages/core/src/Panels/Panel.php — весь файл, ни одного упоминания
 * "connection"), а config('vaulter.connection') не читается нигде в src.
 */
it('гипотетический vaulter.connection не влияет на то, куда PanelTablesBuilder кладёт таблицы панели', function (): void {
    config()->set('database.connections.vaulter_other', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    // Ключа vaulter.connection нет в published-конфиге (packages/core/config/vaulter.php —
    // единственное совпадение "connection" в файле - collaboration.drivers.reverb.connection,
    // это broadcast-канал, не БД-соединение). Выставляем его искусственно: если бы код
    // читал этот ключ где-либо (Panel, PanelTablesBuilder, IdStrategy), таблица панели
    // 'connprobe_storages' оказалась бы на соединении vaulter_other.
    config()->set('vaulter.connection', 'vaulter_other');

    $panel = (new Panel)->id('connprobe')->tablePrefix('connprobe');

    $builder = new PanelTablesBuilder;
    $builder->createTable(panel: $panel, entity: 'storages');

    // Факт: таблица создана на ДЕФОЛТНОМ (testing) соединении, а не на vaulter_other.
    expect(Schema::hasTable('connprobe_storages'))->toBeTrue();
    expect(Schema::connection('vaulter_other')->hasTable('connprobe_storages'))->toBeFalse();

    $builder->dropTable(panel: $panel, entity: 'storages');
})->group('audit-vlt-p2.3');

it('Panel не предоставляет способа задать соединение — нет ни connection(), ни connectionName()', function (): void {
    expect(method_exists(Panel::class, 'connection'))->toBeFalse();
    expect(method_exists(Panel::class, 'connectionName'))->toBeFalse();
});

it('модели ядра не переопределяют $connection — getConnectionName() остаётся null (дефолтное соединение)', function (): void {
    expect((new \Vaulter\Models\Drive\Node)->getConnectionName())->toBeNull();
    expect((new \Vaulter\Models\Drive\Storage)->getConnectionName())->toBeNull();
    expect((new \Vaulter\Models\Access\NodeGrant)->getConnectionName())->toBeNull();
});
