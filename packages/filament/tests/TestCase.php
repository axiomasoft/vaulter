<?php

declare(strict_types=1);

namespace Vaulter\Filament\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Application;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Filament\Tests\Fixtures\TestPanelProvider;
use Vaulter\Filament\VaulterFilamentServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Testing\Support\SuiteDatabaseConnection;
use Vaulter\VaulterServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            // Filament 5 stack (Livewire + the Filament packages) so the resource
            // pages render and their actions can be exercised in Livewire tests.
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,

            // Core + drive are production dependencies; media binds the CAS
            // seam that the drive actions also need in these package fixtures.
            VaulterServiceProvider::class,
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
            VaulterFilamentServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('vaulter.database.write_guard', (bool) env('VAULTER_TEST_WRITE_GUARD', false));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Livewire component rendering needs a started session (the error bag is
        // shared through it); pin an array session so the resource pages render.
        $app['config']->set('session.driver', 'array');

        // Pin the bigint id strategy (the published default is ulid) so the test
        // fixtures use plain integer keys.
        $app['config']->set('vaulter.ids.strategy', 'bigint');

        // The resource tests exercise the REAL ACL: opt into the database host-role
        // driver so AccessResolver resolves grants from v_node_grants (the open
        // 'null' driver would grant everything and hide the authorization tests).
        $app['config']->set('vaulter-drive.access.permission_driver', 'database');

        $app['config']->set('vaulter.routes.middleware.authenticated', []);
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (SuiteDatabaseConnection::migrationPaths($this->app) as $path) {
            $this->loadMigrationsFrom($path);
        }
    }
}
