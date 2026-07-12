<?php

declare(strict_types=1);

namespace Vaulter\Tests\P22Repro;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Filament\VaulterFilamentServiceProvider;
use Vaulter\VaulterServiceProvider;

abstract class PublishTagsFilamentBase extends OrchestraTestCase
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            VaulterServiceProvider::class,
            VaulterFilamentServiceProvider::class,
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
