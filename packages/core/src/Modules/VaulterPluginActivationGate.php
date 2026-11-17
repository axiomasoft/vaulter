<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Registry\PluginActivationGate;
use Vaulter\Kernel;

/**
 * Bridges Packman's {@see PluginActivationGate} to Vaulter's sealed {@see Kernel} module activation map.
 */
#[Internal]
final readonly class VaulterPluginActivationGate implements PluginActivationGate
{
    public function __construct(private Application $app) {}

    #[Override]
    public function isEnabled(string $pluginId): bool
    {
        $kernel = $this->app->make(Kernel::class);
        $moduleId = PluginModuleBridge::moduleId($pluginId);

        if ($kernel->isSealed()) {
            return $kernel->isValid() && $kernel->isActive($moduleId);
        }

        if ($this->app->bound(Repository::class)) {
            $override = $this->app->make(Repository::class)->get('vaulter.modules.'.$moduleId);
            if (is_bool($override)) {
                return $override;
            }
        }

        foreach (ModuleCatalog::of($this->app)->pluginSpecs() as $pluginClass => $spec) {
            if (!class_exists($pluginClass)) {
                continue;
            }
            $plugin = new $pluginClass;
            if ($plugin->manifest()->id === $pluginId) {
                return $spec->activation === Activation::Auto;
            }
        }

        return true;
    }
}
