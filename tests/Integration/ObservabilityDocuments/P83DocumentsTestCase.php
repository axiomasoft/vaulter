<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\ObservabilityDocuments;

use Illuminate\Foundation\Application;
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
        ];
    }
}
