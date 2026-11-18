<?php

declare(strict_types=1);

namespace Vaulter\Media\Providers;

use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\Laravel\VaulterPluginRegistrar;
use Vaulter\Media\MediaModule;

/**
 * Registers Packman plugin/extension facets and Vaulter module runtime metadata for {@see MediaModule}.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class MediaPackageRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        VaulterPluginRegistrar::register($app, MediaModule::class, dirname(__DIR__, 2).'/composer.json');

    }

    #[Override]
    public function boot(Application $app): void {}
}
