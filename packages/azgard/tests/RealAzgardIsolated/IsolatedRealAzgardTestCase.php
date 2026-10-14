<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Tests\RealAzgardIsolated;

use AzGuard\AzGuardServiceProvider;
use Illuminate\Foundation\Application;
use Vaulter\Azgard\Tests\RealAzgard\RealAzgardTestCase;
use Vaulter\Azgard\Tests\RealAzgardIsolated\Stubs\IsolatedDocumentsPanel;
use Vaulter\Azgard\VaulterAzgardServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * The real-ecosystem case on the {@see IsolatedDocumentsPanel}: same host tables and
 * morph map as {@see RealAzgardTestCase}, another panel.
 */
abstract class IsolatedRealAzgardTestCase extends RealAzgardTestCase
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
            VaulterAzgardServiceProvider::class,
        ];
    }
}
