<?php

declare(strict_types=1);

namespace Vaulter\Tests\ObservabilityDocuments;

use Illuminate\Foundation\Application;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Tests\TestCase;

abstract class P83DocumentsTestCase extends TestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            VaulterDocumentsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.features.document_versions', true);
        $app['config']->set('vaulter.features.collaboration', true);
        $app['config']->set('vaulter.collaboration.drivers.hocuspocus.secret', 'vlt-load-dev-collab-secret-not-for-production');
    }
}
