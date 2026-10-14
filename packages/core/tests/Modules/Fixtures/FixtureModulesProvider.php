<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

use Illuminate\Support\ServiceProvider;
use Vaulter\Modules\OffersModules;

/** A package provider as the kernel expects one: it offers its manifests and registers nothing itself. */
final class FixtureModulesProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(HostModule::class);
        $this->offerModule(PluginModule::class);
        $this->offerModule(OptionalModule::class);
    }
}
