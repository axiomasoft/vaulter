<?php

declare(strict_types=1);

namespace Vaulter\Tests\P24Repro;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * P2.4 C1/C2/C4 repro — "composer require" без единого vendor:publish.
 *
 * НАМЕРЕННО не переопределяет defineDatabaseMigrations() (в отличие от
 * Vaulter\Tests\TestCase) и НЕ вызывает loadMigrationsFrom() вручную: цель —
 * проверить, что миграции реально поднимаются штатным механизмом
 * discoversMigrations()->runsMigrations() провайдера (PT2.1), а не тестовым
 * харнессом пакета. НЕ публикует config/vaulter.php — читает голые дефолты.
 *
 * ВАЖНО (харнесс-артефакт, НЕ баг пакета): первый прогон этого файла падал с
 * «table v_storages already exists» на первой же миграции. Причина —
 * НЕ двойная регистрация провайдера (проверено: testbench.yaml НЕ подмешивает
 * providers для голого Orchestra\Testbench\TestCase вне WithWorkbench), а
 * стороннее ЗАГРЯЗНЕНИЕ общего Testbench-скелета: item P2.2 ранее физически
 * публиковал (`vendor:publish --tag=vaulter-migrations`) файлы миграций в
 * `vendor/orchestra/testbench-core/laravel/database/migrations/` — ЭТОТ путь
 * Laravel сканирует ВСЕГДА (дефолтный `database_path('migrations')`) в
 * ДОПОЛНЕНИЕ к путям из `discoversMigrations()`. Artisan `migrate` видел
 * одну и ту же схему из ДВУХ мест. Устранено удалением осевших файлов из
 * `vendor/` (не отслеживается git — вне области D2) перед прогоном; сам факт
 * зафиксирован в находках как риск харнесса, а не пакета.
 */
abstract class NoPublishBase extends OrchestraTestCase
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
        // Единственное, что задаёт "хост" — координаты БД (DB_*/APP_KEY — общий
        // минимум C2) и APP_KEY, без которых не поднимается ни один Laravel-хост.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
