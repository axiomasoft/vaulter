<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Schema\Laravel\SchemaExtensions;

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
        ExtensionRegistrar::builtIn($plugin);
        $profile = ExtensionFacetRegistrar::register($app, $plugin, $composerManifest);
        SchemaExtensions::register($app, $plugin);

        foreach ($profile->healthChecks as $check) {
            $app->bind($check);
        }
    }
}
