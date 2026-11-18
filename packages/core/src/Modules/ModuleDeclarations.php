<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Packman\Attributes\Internal;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Internal\Graph\PluginDeclarations;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\CoreModule;

/**
 * Translates {@see ExtensionPlugin} declarations and optional {@see PluginModuleSpec} metadata into {@see ModuleBuilder}
 * declarations for the Vaulter domain kernel. Only native Packman code plugins are accepted.
 */
#[Internal]
final class ModuleDeclarations
{
    public static function moduleId(string $pluginId): string
    {
        $stripped = str_starts_with($pluginId, 'vaulter.') ? substr($pluginId, strlen('vaulter.')) : $pluginId;

        return str_replace('.', '-', $stripped);
    }

    public static function protocolVersion(string $semver): string
    {
        if (preg_match('/^(\d+)\.(\d+)/', $semver, $matches) === 1) {
            return $matches[1].'.'.$matches[2];
        }

        return $semver;
    }

    /** @return array<string, string> module id => constraint */
    public static function requires(ExtensionPlugin $plugin, ?PluginModuleSpec $spec = null): array
    {
        $runtime = $spec ?? ($plugin instanceof WithModuleRuntime ? $plugin->moduleRuntime() : null);
        if ($runtime?->requires !== null) {
            $mapped = [];
            foreach ($runtime->requires as $dependency => $constraint) {
                $key = preg_match(PluginManifest::ID_PATTERN, $dependency) === 1
                    ? self::moduleId($dependency)
                    : $dependency;
                $mapped[$key] = $constraint;
            }

            return $mapped;
        }
        $requires = [];
        foreach ($plugin->manifest()->requires as $dependency) {
            $requires[self::moduleId($dependency)] = '^1.0';
        }

        return $requires;
    }

    public static function buildOffered(string $manifest, ?PluginModuleSpec $spec = null): ?ModuleBuilder
    {
        if (is_a($manifest, ExtensionPlugin::class, true)) {
            $plugin = new $manifest;
            $builder = new ModuleBuilder(new ModuleId(self::moduleId($plugin->manifest()->id)));
            self::populateFromPlugin($builder, $plugin, $spec);

            return $builder;
        }

        return null;
    }

    public static function populateFromPlugin(ModuleBuilder $builder, ExtensionPlugin $plugin, ?PluginModuleSpec $spec = null): void
    {
        $spec ??= $plugin instanceof WithModuleRuntime ? $plugin->moduleRuntime() : null;

        if ($plugin instanceof WithConfiguration) {
            $root = $plugin->configRoot();
            $defaultPath = $plugin->defaultConfigPath();
            if ($root !== '' && $defaultPath !== null && $defaultPath !== '') {
                $builder->config($root, $defaultPath);
            }
        }

        if ($plugin instanceof WithSchema) {
            $migrationPath = $plugin->migrationPath();
            if ($migrationPath !== null && $migrationPath !== '') {
                $builder->schema($migrationPath);
            }
        }

        $facetCapDefs = [];
        $facetCapProviders = [];
        if ($plugin instanceof WithCapabilities) {
            foreach ($plugin->definedCapabilities() as $contract => $cardinality) {
                $card = Cardinality::from($cardinality);
                $builder->defineCapability($contract, $card);
                $facetCapDefs[$contract.'|'.$card->value] = true;
            }
            foreach ($plugin->providedCapabilities() as $contract => $provider) {
                if (is_string($provider)) {
                    $builder->provides($contract, $provider);
                    $facetCapProviders[$contract.'|class|'.$provider] = true;
                } else {
                    $default = $provider['default'] ?? null;
                    $builder->providesFromConfig($contract, $provider['config'], $default);
                    $facetCapProviders[$contract.'|config|'.$provider['config'].'|'.($default ?? '')] = true;
                }
            }
        }

        $facetSlotDefs = [];
        $facetSlotContribs = [];
        if ($plugin instanceof WithSlots) {
            foreach ($plugin->definedSlots() as $contract => $definition) {
                $kind = SlotKind::from($definition['kind']);
                $card = Cardinality::from($definition['cardinality'] ?? Cardinality::Many->value);
                $builder->defineSlot($contract, $kind, $card);
                $facetSlotDefs[$contract.'|'.$kind->value.'|'.$card->value] = true;
            }
            foreach ($plugin->slotContributions() as $contribution) {
                $priority = $contribution['priority'] ?? 0;
                $key = $contribution['key'] ?? null;
                $builder->slot(
                    $contribution['slot'],
                    $contribution['implementation'],
                    $contribution['id'],
                    $priority,
                    $key,
                );
                $facetSlotContribs[$contribution['slot'].'|'.$contribution['id'].'|'.$contribution['implementation'].'|'.$priority.'|'.($key ?? '')] = true;
            }
        }

        $pluginId = $plugin instanceof CoreModule ? 'vaulter.core' : $plugin->manifest()->id;
        $declarations = new PluginDeclarations;
        $plugin->register(new PluginRegistrar($declarations, $pluginId));
        $declarations->seal();

        foreach ($declarations->capabilityDefinitions() as $capDef) {
            $card = Cardinality::from($capDef->cardinality->value);
            $sig = $capDef->contract.'|'.$card->value;
            if (!isset($facetCapDefs[$sig])) {
                $builder->defineCapability($capDef->contract, $card);
            }
        }
        foreach ($declarations->capabilityProviders() as $capProvider) {
            if ($capProvider->configKey !== null) {
                $sig = $capProvider->contract.'|config|'.$capProvider->configKey.'|'.($capProvider->default ?? '');
                if (!isset($facetCapProviders[$sig])) {
                    $builder->providesFromConfig($capProvider->contract, $capProvider->configKey, $capProvider->default);
                }
            } elseif ($capProvider->implementation !== null) {
                $sig = $capProvider->contract.'|class|'.$capProvider->implementation;
                if (!isset($facetCapProviders[$sig])) {
                    $builder->provides($capProvider->contract, $capProvider->implementation);
                }
            }
        }
        foreach ($declarations->slotDefinitions() as $slotDef) {
            $kind = SlotKind::from($slotDef->kind->value);
            $card = Cardinality::from($slotDef->cardinality->value);
            $sig = $slotDef->contract.'|'.$kind->value.'|'.$card->value;
            if (!isset($facetSlotDefs[$sig])) {
                $builder->defineSlot($slotDef->contract, $kind, $card);
            }
        }
        foreach ($declarations->slotContributions() as $slotContrib) {
            $sig = $slotContrib->slot.'|'.$slotContrib->id.'|'.$slotContrib->implementation.'|'.$slotContrib->priority.'|'.($slotContrib->key ?? '');
            if (!isset($facetSlotContribs[$sig])) {
                $builder->slot(
                    $slotContrib->slot,
                    $slotContrib->implementation,
                    $slotContrib->id,
                    $slotContrib->priority,
                    $slotContrib->key,
                );
            }
        }

        if ($spec === null) {
            return;
        }

        foreach ($spec->routes as $file) {
            $builder->routes($file);
        }
        foreach ($spec->translations as $namespace => $dir) {
            $builder->translations($dir, $namespace);
        }
        if ($spec->commands !== []) {
            $builder->commands(...$spec->commands);
        }
        foreach ($spec->listeners as $listener) {
            $builder->listen($listener['event'], $listener['listener']);
        }
        foreach ($spec->schedules as $schedule) {
            $builder->schedule($schedule);
        }
        foreach ($spec->routeMiddleware as $alias => $middleware) {
            $builder->routeMiddleware($alias, $middleware);
        }
        foreach ($spec->bindings as $binding) {
            $builder->bindings($binding);
        }
        foreach ($spec->events as $enum) {
            $builder->events($enum);
        }
        foreach ($spec->problems as $source) {
            $builder->problems($source);
        }
        foreach ($spec->operations as $source) {
            $builder->operations($source);
        }
    }
}
