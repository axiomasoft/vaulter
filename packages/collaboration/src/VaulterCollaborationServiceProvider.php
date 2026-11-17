<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Vaulter\Modules\OffersModules;

/**
 * The collaboration package only offers {@see CollaborationModule}; the core module kernel registers what the module
 * declares, so a host that leaves the opt-in module off (`vaulter.modules.collaboration`) gets none of it.
 */
#[Api]
final class VaulterCollaborationServiceProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(CollaborationModule::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'vaulter-collaboration');
    }
}
