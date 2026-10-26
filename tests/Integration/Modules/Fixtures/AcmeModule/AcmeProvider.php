<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule;

use Illuminate\Support\ServiceProvider;
use Vaulter\Modules\OffersModules;

/** The package provider of the AcmeModule: it offers the manifest and registers nothing else. */
final class AcmeProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(AcmeModule::class);
    }
}
