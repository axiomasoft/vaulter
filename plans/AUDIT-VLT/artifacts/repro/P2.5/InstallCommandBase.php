<?php

declare(strict_types=1);

namespace Vaulter\Tests\P25Repro;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * P2.5 C1/C2 repro — boot a fresh Testbench app with the four root-vendor-autoloadable
 * providers (core/media/drive/documents — filament and permissions-azgard have their
 * own vendor/ trees, same constraint as P2.2's PublishTagsBase) and run the REAL
 * `vaulter:install` / `vendor:publish --tag=…` artisan commands non-interactively,
 * capturing actual stdout — per Code Guidance: C1/C2/C4 must not be closed by reading.
 */
abstract class InstallCommandBase extends OrchestraTestCase
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
