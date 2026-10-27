<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Collaboration;

trait WithAcme
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), AcmeProvider::class];
    }
}
