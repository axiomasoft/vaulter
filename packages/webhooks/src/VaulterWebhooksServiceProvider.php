<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\Webhooks\Providers\WebhooksPackageRegistrar;

/**
 * Vaulter Webhooks — signed outbound webhook endpoints, delivery retries, and SSRF guard. Registers Packman facets and offers {@see WebhooksModule}.
 */
#[Api(since: '1.0')]
final class VaulterWebhooksServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        RegistersPackageRegistrars::register as protected registerRegistrars;
        RegistersPackageRegistrars::boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        WebhooksPackageRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'vaulter-webhooks');
        $this->bootRegistrars();
    }
}
