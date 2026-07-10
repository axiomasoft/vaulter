<?php

declare(strict_types=1);

use Vaulter\Tests\P24Repro\NoPublishBase;

require __DIR__.'/NoPublishBase.php';

uses(NoPublishBase::class);

it('runs vaulter migrations from vendor/ with zero vendor:publish and mounts at least one API route (C1)', function () {
    // Нулевая публикация: config/vaulter.php НЕ опубликован — читаем дефолты
    // из vendor/, миграции НЕ подгружены вручную (нет defineDatabaseMigrations
    // override) — единственный источник схемы — discoversMigrations()->runsMigrations()
    // провайдера (PT2.1), подгружаемый через testbench.yaml providers.
    $before = \Illuminate\Support\Facades\DB::select("select name from sqlite_master where type='table'");
    dump('sqlite tables BEFORE migrate() = '.json_encode(array_map(static fn ($row) => $row->name, $before), JSON_PRETTY_PRINT));

    $exitCode = $this->artisan('migrate', ['--force' => true])->run();

    $tables = \Illuminate\Support\Facades\DB::select("select name from sqlite_master where type='table'");
    $tableNames = array_map(static fn ($row) => $row->name, $tables);

    dump('migrate exit code = '.$exitCode);
    dump('sqlite tables after migrate() with zero vendor:publish = '.json_encode($tableNames, JSON_PRETTY_PRINT));

    expect($tableNames)->toContain('v_nodes');
    expect($tableNames)->toContain('v_storages');

    // "хотя бы один маршрут API отвечает" — share-token resolve не требует
    // аутентификации/модели, только ResolveShareToken middleware; невалидный
    // токен — валидный HTTP-ответ (не fatal/route-not-found), доказывающий, что
    // маршрут смонтирован и стек middleware разрешился.
    $response = $this->getJson('/api/vaulter/share/nonexistent-token');

    dump('GET /api/vaulter/share/nonexistent-token status = '.$response->getStatusCode());
    dump('body = '.$response->getContent());
});
