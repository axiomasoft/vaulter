<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Schema\Laravel\SchemaExtensions;
use Throwable;

/**
 * Registers Packman plugin, extension, schema, and non-sticky doctor check bindings
 * for a Vaulter module plugin without prematurely caching options inside checks.
 */
#[Internal]
final class VaulterExtensionFacets
{
    /** @param class-string<ExtensionPlugin> $plugin */
    public static function register(Application $app, string $plugin, ?string $composerManifest = null): void
    {
        $instance = new $plugin;
        $ownerId = null;
        try {
            $ownerId = $instance->manifest()->id;
        } catch (Throwable) {
        }

        ExtensionRegistrar::builtInFor($app, $plugin);
        // Installed schemas remain available when a module is temporarily disabled.
        SchemaExtensions::register($app, $instance, $ownerId);
        ModuleCatalog::of($app)->onActivation($plugin, static function () use ($app, $instance, $composerManifest): void {
            $config = $app->make(Repository::class);
            $root = $instance instanceof WithConfiguration ? $instance->configRoot() : '';
            $hadRoot = $root !== '' && $config->has($root);
            $host = $hadRoot ? $config->get($root) : null;
            $profile = ExtensionFacetRegistrar::register($app, $instance, $composerManifest);
            if ($hadRoot) {
                // ModuleApplier merges shipped defaults using Vaulter's replacement-map semantics.
                $config->set($root, $host);
            }

            foreach ($profile->healthChecks as $check) {
                $app->bind($check);
            }
        });
    }
}
