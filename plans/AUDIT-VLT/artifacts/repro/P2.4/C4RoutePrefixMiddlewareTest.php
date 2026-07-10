<?php

declare(strict_types=1);

namespace Vaulter\Tests\P24Repro;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * C4 — route_prefix/middleware должны примениться ко ВСЕМ ТРЁМ файлам
 * маршрутов (core/routes/api.php, drive/routes/drive.php,
 * documents/routes/documents.php), а не только к core. Конфиг задаётся ДО
 * boot() (в defineEnvironment), т.к. Route::prefix(Config::routePrefix())
 * читается один раз при регистрации маршрутов.
 */
abstract class C4Base extends OrchestraTestCase
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

        $app['config']->set('vaulter.route_prefix', 'internal/vlt-custom');
        $app['config']->set('vaulter.middleware', ['api']);
        // Включаем фичи, гейтящие маршруты drive/documents, чтобы все три
        // файла реально смонтировали свои группы.
        $app['config']->set('vaulter.features.document_versions', true);
        $app['config']->set('vaulter.features.comments', true);
        $app['config']->set('vaulter.features.uploads', true);
        $app['config']->set('vaulter.features.grants', true);
        $app['config']->set('vaulter.features.reliability', true);
    }
}

uses(C4Base::class);

it('applies the custom route_prefix to core, drive AND documents route files (C4)', function () {
    $routes = collect($this->app['router']->getRoutes())->map(fn ($r) => $r->uri());

    $customPrefixed = $routes->filter(fn ($uri) => str_starts_with($uri, 'internal/vlt-custom'));
    $defaultPrefixed = $routes->filter(fn ($uri) => str_starts_with($uri, 'api/vaulter'));

    dump('routes under custom prefix internal/vlt-custom (count='.$customPrefixed->count().') = '.json_encode($customPrefixed->values(), JSON_PRETTY_PRINT));
    dump('routes STILL under default api/vaulter (count='.$defaultPrefixed->count().') = '.json_encode($defaultPrefixed->values(), JSON_PRETTY_PRINT));

    // Один маршрут-представитель каждого из трёх файлов:
    // core: nodes/{node}/grants ; drive: storages/{storage}/nodes ; documents: nodes/{node}/content
    $coreRoute = $customPrefixed->first(fn ($u) => str_contains($u, 'grants'));
    $driveRoute = $customPrefixed->first(fn ($u) => str_contains($u, 'storages/{storage}/nodes'));
    $documentsRoute = $customPrefixed->first(fn ($u) => str_contains($u, 'content'));

    dump('core-owned route under custom prefix = '.json_encode($coreRoute));
    dump('drive-owned route under custom prefix = '.json_encode($driveRoute));
    dump('documents-owned route under custom prefix = '.json_encode($documentsRoute));

    expect($defaultPrefixed->count())->toBe(0, 'ни один vaulter-маршрут не должен остаться под дефолтным api/vaulter после смены route_prefix');
    expect($coreRoute)->not->toBeNull();
    expect($driveRoute)->not->toBeNull();
    expect($documentsRoute)->not->toBeNull();
});
