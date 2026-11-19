<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Closure;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Vaulter\Exceptions\Registry\RegistryFrozenException;

/**
 * The manifests offered by the package providers while the application registers. The kernel reads
 * them once, in its booting callback, and seals the catalogue: a provider registered after boot cannot
 * offer a module any more.
 */
#[Internal]
final class ModuleCatalog
{
    /** @var list<string> */
    private array $offered = [];

    /** @var array<class-string<ExtensionPlugin>, PluginModuleSpec> */
    private array $pluginSpecs = [];

    /** @var array<class-string<ExtensionPlugin>, Closure(): void> */
    private array $activationCallbacks = [];

    private bool $sealed = false;

    /**
     * @param class-string<ExtensionPlugin> $plugin
     * @param Closure(): void               $callback
     */
    public function onActivation(string $plugin, Closure $callback): void
    {
        if ($this->sealed) {
            throw RegistryFrozenException::forRegistry('module');
        }
        $this->activationCallbacks[$plugin] = $callback;
    }

    public function activateFacets(CompiledModules $compiled): void
    {
        foreach ($compiled->modules as $module) {
            if ($module->active && isset($this->activationCallbacks[$module->manifest])) {
                ($this->activationCallbacks[$module->manifest])();
            }
        }
    }

    /** The catalogue of the application; any provider may be the first to offer, so it binds itself. */
    public static function of(Container $app): self
    {
        $app->singletonIf(self::class);

        return $app->make(self::class);
    }

    /** @param class-string<ExtensionPlugin> $manifest */
    public function offer(string $manifest): void
    {
        if ($this->sealed) {
            throw RegistryFrozenException::forRegistry('module');
        }
        if (!in_array($manifest, $this->offered, true)) {
            $this->offered[] = $manifest;
            if (is_a($manifest, WithModuleRuntime::class, true)) {
                $this->pluginSpecs[$manifest] = (new $manifest)->moduleRuntime();
            }
        }
    }

    public function offerPlugin(PluginModuleSpec $spec): void
    {
        $this->offer($spec->plugin);
        $this->pluginSpecs[$spec->plugin] = $spec;
    }

    /** @return list<string> in the order of the offers */
    public function offered(): array
    {
        return $this->offered;
    }

    /** @return array<class-string<ExtensionPlugin>, PluginModuleSpec> */
    public function pluginSpecs(): array
    {
        return $this->pluginSpecs;
    }

    public function pluginSpec(string $manifest): ?PluginModuleSpec
    {
        return $this->pluginSpecs[$manifest] ?? null;
    }

    public function seal(): void
    {
        $this->sealed = true;
    }
}
