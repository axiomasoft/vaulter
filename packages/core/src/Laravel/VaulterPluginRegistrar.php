<?php

declare(strict_types=1);

namespace Vaulter\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\VaulterExtensionFacets;

/** Offers a native code plugin to Vaulter and registers its Packman facets. */
#[Spi(SpiKind::Call, since: '1.0')]
final class VaulterPluginRegistrar
{
    /** @param class-string<ExtensionPlugin> $plugin */
    public static function register(Application $app, string $plugin, ?string $composerManifest = null): void
    {
        ModuleCatalog::of($app)->offer($plugin);
        VaulterExtensionFacets::register($app, $plugin, $composerManifest);
    }
}
