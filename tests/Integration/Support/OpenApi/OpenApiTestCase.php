<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Support\OpenApi;

use Illuminate\Foundation\Application;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Integration\Tests\TestCase;

/**
 * Boots every package that owns v1 routes, so one application carries the whole route table the contract describes.
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
        return [...parent::getPackageProviders($app), VaulterDocumentsServiceProvider::class];
    }
}
