<?php

declare(strict_types=1);

namespace Vaulter\Tests\VerifyFP22_02Clean;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * Скептик-репро F-P2.2-02, ВТОРОЙ независимый заход: устраняет возражение
 * "DuplicateRunTest всего лишь подобрал leftover-файлы от предыдущих item'ов
 * аудита" — явно ЧИСТИТ database/migrations() ДО теста, затем воспроизводит
 * ИМЕННО документированную (docs/guide/installation.md:75-80) последовательность
 * хоста: (1) свежий install без публикации -> migrate (вендорный автозапуск
 * создаёт схему), (2) ПОЗЖЕ хост публикует ради правки схемы
 * (`vendor:publish --tag=vaulter-migrations`), (3) хост запускает migrate ещё раз.
 */
abstract class CleanSequenceBase extends OrchestraTestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        // Явно чистим default migrations path ДО теста — исключает "унаследовал
        // leftover от другого item'а аудита" как возражение.
        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            @unlink($file);
        }
    }
}
