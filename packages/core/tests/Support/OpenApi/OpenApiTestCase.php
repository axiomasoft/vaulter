<?php

declare(strict_types=1);

namespace Vaulter\Tests\Support\OpenApi;

use Illuminate\Foundation\Application;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Tests\TestCase;

/**
 * Boots every package that owns v1 routes (core, drive, documents) with the opt-in hub stack
 * on, so one application carries the whole route table the contract describes.
 */
abstract class OpenApiTestCase extends TestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), VaulterDocumentsServiceProvider::class, VaulterCollaborationServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.modules.collaboration', true);
    }
}
