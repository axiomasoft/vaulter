<?php

declare(strict_types=1);

namespace Vaulter\Filament\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Filament\VaulterFilamentServiceProvider;
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
            VaulterServiceProvider::class,
            VaulterFilamentServiceProvider::class,
        ];
    }
}
