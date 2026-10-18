<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\GuardedModule;

use Illuminate\Support\ServiceProvider;
use Vaulter\Modules\OffersModules;

/** The package provider of the GuardedModule: it offers the manifest and registers nothing else. */
final class GuardedProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(GuardedModule::class);
    }
}
