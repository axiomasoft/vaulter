<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;
use Vaulter\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * P2.3 / C6 — «connection» не параметризован (E2.3). Repro: регистрируем ВТОРОЕ
 * sqlite-соединение и выставляем гипотетический ключ config('vaulter.connection'),
 * который код НИГДЕ не читает (grep: 0 попаданий Schema::connection(...) /
 * ->on(config('vaulter.connection')) в packages/*\/src). Если бы соединение было
 * параметризовано, эта настройка перенаправила бы схему/модели на второе
 * соединение; по факту таблица создаётся на дефолтном соединении вне зависимости
 * от значения этого ключа — соединение захардкожено на фасад Schema/DB по
 * умолчанию.
 */
it('гипотетический vaulter.connection не влияет на то, куда PanelTablesBuilder создаёт таблицы', function (): void {
    config()->set('database.connections.vaulter_other', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    // Ключа vaulter.connection не существует в published-конфиге (grep §C6) —
    // выставляем его искусственно, чтобы доказать, что даже если бы host его
    // выставил, код бы его не прочитал.
    config()->set('vaulter.connection', 'vaulter_other');

    $builder = new PanelTablesBuilder;
    $panel = (new Panel)->id('conntest');

    $builder->createTable(panel: $panel->tablePrefix('conntest'), entity: 'storages');

    // Таблица оказалась на ДЕФОЛТНОМ (testing) соединении, а не на vaulter_other —
    // Schema-фасад в PanelTablesBuilder не учитывает config('vaulter.connection').
    expect(Schema::hasTable('conntest_storages'))->toBeTrue();
    expect(Schema::connection('vaulter_other')->hasTable('conntest_storages'))->toBeFalse();

    // Модели тоже не читают config('vaulter.connection') — getConnectionName()
    // остаётся null (дефолтное соединение), не переопределяется.
    $node = new \Vaulter\Models\Drive\Node;
    expect($node->getConnectionName())->toBeNull();

    $builder->dropTable(panel: $panel, entity: 'storages');
});
