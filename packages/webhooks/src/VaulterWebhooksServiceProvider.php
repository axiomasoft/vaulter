<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Vaulter\Modules\OffersModules;

/**
 * The webhooks package only offers {@see WebhooksModule}; the core module kernel registers what the module
 * declares, so a host that leaves the opt-in module off (`vaulter.modules.webhooks`) gets none of it.
 */
#[Api]
final class VaulterWebhooksServiceProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(WebhooksModule::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'vaulter-webhooks');
    }
}
