<?php

declare(strict_types=1);

namespace DocVault\Filament\Tests;

use DocVault\DocVaultServiceProvider;
use DocVault\Filament\DocVaultFilamentServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DocVaultServiceProvider::class,
            DocVaultFilamentServiceProvider::class,
        ];
    }
}
