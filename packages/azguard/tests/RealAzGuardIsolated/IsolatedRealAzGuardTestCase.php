<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Tests\RealAzGuardIsolated;

use AzGuard\AzGuardServiceProvider;
use Illuminate\Foundation\Application;
use Vaulter\AzGuard\Tests\RealAzGuard\RealAzGuardTestCase;
use Vaulter\AzGuard\Tests\RealAzGuardIsolated\Stubs\IsolatedDocumentsPanel;
use Vaulter\AzGuard\VaulterAzGuardServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * The real-ecosystem case on the {@see IsolatedDocumentsPanel}: same host tables and
 * morph map as {@see RealAzGuardTestCase}, another panel.
 */
abstract class IsolatedRealAzGuardTestCase extends RealAzGuardTestCase
{
    /**
     * @param  Application        $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AzGuardServiceProvider::class,
            IsolatedDocumentsPanel::class,
            VaulterServiceProvider::class,
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
            VaulterAzGuardServiceProvider::class,
        ];
    }
}
