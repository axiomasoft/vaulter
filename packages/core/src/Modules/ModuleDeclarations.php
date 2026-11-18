<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Packman\Attributes\Internal;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;

/**
 * Translates {@see ExtensionPlugin} declarations and optional {@see PluginModuleSpec} metadata into {@see ModuleBuilder}
 * declarations for the Vaulter domain kernel. Only native Packman code plugins are accepted.
 */
#[Internal]
final class ModuleDeclarations
{
    public static function moduleId(string $pluginId): string
    {
        return str_starts_with($pluginId, 'vaulter.') ? substr($pluginId, strlen('vaulter.')) : $pluginId;
    }

    public static function protocolVersion(string $semver): string
    {
        if (preg_match('/^(\d+)\.(\d+)/', $semver, $matches) === 1) {
            return $matches[1].'.'.$matches[2];
        }

        return $semver;
    }

    /** @return array<string, string> module id => constraint */
    public static function requires(ExtensionPlugin $plugin): array
    {
        $runtime = $plugin instanceof WithModuleRuntime ? $plugin->moduleRuntime() : null;
        if ($runtime?->requires !== null) {
            return $runtime->requires;
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

        if ($plugin instanceof WithCapabilities) {
            foreach ($plugin->definedCapabilities() as $contract => $cardinality) {
                $builder->defineCapability($contract, Cardinality::from($cardinality));
            }
            foreach ($plugin->providedCapabilities() as $contract => $provider) {
                if (is_string($provider)) {
                    $builder->provides($contract, $provider);
                } else {
                    $builder->providesFromConfig($contract, $provider['config'], $provider['default'] ?? null);
                }
            }
        }

        if ($plugin instanceof WithSlots) {
            foreach ($plugin->definedSlots() as $contract => $definition) {
                $builder->defineSlot(
                    $contract,
                    SlotKind::from($definition['kind']),
                    Cardinality::from($definition['cardinality'] ?? Cardinality::Many->value),
                );
            }
            foreach ($plugin->slotContributions() as $contribution) {
                $builder->slot(
                    $contribution['slot'],
                    $contribution['implementation'],
                    $contribution['id'],
                    $contribution['priority'] ?? 0,
                    $contribution['key'] ?? null,
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
