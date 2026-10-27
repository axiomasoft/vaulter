<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\ObservabilityDocuments;

use Illuminate\Foundation\Application;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Integration\Tests\TestCase;

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
            VaulterCollaborationServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.modules.collaboration', true);
        $app['config']->set('vaulter-collaboration.hub.jwt_keys', ['load-1' => 'vlt-load-dev-collab-jwt-key-not-for-production']);
        $app['config']->set('vaulter-collaboration.hub.jwt_active_kid', 'load-1');
        $app['config']->set('vaulter-collaboration.hub.webhook_secret', 'vlt-load-dev-collab-webhook-not-for-production');
    }
}
