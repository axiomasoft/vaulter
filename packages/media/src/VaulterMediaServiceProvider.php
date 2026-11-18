<?php

declare(strict_types=1);

namespace Vaulter\Media;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\Media\Providers\MediaPackageRegistrar;

/**
 * Vaulter Media — the content-addressed file/blob layer (CAS). Registers Packman facets and offers {@see MediaModule}.
 */
#[Api(since: '1.0')]
final class VaulterMediaServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        RegistersPackageRegistrars::register as protected registerRegistrars;
        RegistersPackageRegistrars::boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        MediaPackageRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'vaulter-media');
        $this->bootRegistrars();
    }
}
