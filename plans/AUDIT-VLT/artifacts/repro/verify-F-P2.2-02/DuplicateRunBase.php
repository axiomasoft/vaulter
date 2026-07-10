<?php

declare(strict_types=1);

namespace Vaulter\Tests\VerifyFP22_02;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * Скептик-репро F-P2.2-02: ЦЕЛЬ — проверить, действительно ли publishMigrations()
 * (тег vaulter-migrations, копия с НОВЫМ timestamp) + runsMigrations() (вендорный
 * автозапуск, остаётся активным всегда — provider не даёт способа его выключить)
 * приводят к падению `migrate` на "table already exists" на СВЕЖЕЙ БД, когда
 * ОБА пути миграций видны Migrator'у одновременно.
 *
 * Тот же базовый Testbench-скелет (vendor/orchestra/testbench-core/laravel), что
 * и у P2.2/P2.4/P2.5. НЕ переопределяет defineDatabaseMigrations() — единственные
 * источники схемы: (1) discoversMigrations()->runsMigrations() провайдера,
 * (2) что бы ни лежало на диске в database/migrations() скелета (дефолтный путь
 * Laravel, который сканируется ВСЕГДА).
 */
abstract class DuplicateRunBase extends OrchestraTestCase
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
    }
}
