<?php

declare(strict_types=1);

namespace Vaulter\Documents\Providers;

use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\Documents\DocumentsModule;
use Vaulter\Laravel\VaulterPluginRegistrar;

/**
 * Registers Packman plugin/extension facets and Vaulter module runtime metadata for {@see DocumentsModule}.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class DocumentsPackageRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        VaulterPluginRegistrar::register($app, DocumentsModule::class, dirname(__DIR__, 2).'/composer.json');
    }

    #[Override]
    public function boot(Application $app): void {}
}
