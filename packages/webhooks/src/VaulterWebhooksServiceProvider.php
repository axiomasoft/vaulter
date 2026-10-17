<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Attributes\Api;
use Vaulter\Modules\OffersModules;

/**
 * The webhooks package only offers {@see WebhooksModule}; the core module kernel registers what the module
 * declares, so a host that leaves the opt-in module off (`vaulter.modules.webhooks`) gets none of it.
 */
#[Api]
final class VaulterWebhooksServiceProvider extends PackageServiceProvider
{
    use OffersModules;

    public function configurePackage(Package $package): void
    {
        $package->name('vaulter-webhooks')->hasTranslations();
    }

    public function packageRegistered(): void
    {
        $this->offerModule(WebhooksModule::class);
    }
}
