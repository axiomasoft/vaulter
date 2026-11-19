<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
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
        $catalog = ModuleCatalog::of($this->app);
        foreach ($catalog->offered() as $pluginClass) {
            if (!is_a($pluginClass, ExtensionPlugin::class, true) || (new $pluginClass)->manifest()->id !== $pluginId) {
                continue;
            }
            $kernel = $this->app->make(Kernel::class);
            $moduleId = ModuleDeclarations::moduleId($pluginId);
            if ($kernel->isSealed()) {
                return $kernel->isValid() && $kernel->isActive($moduleId);
            }
            if ($this->app->bound(Repository::class)) {
                $override = $this->app->make(Repository::class)->get('vaulter.modules.'.$moduleId);
                if (is_bool($override)) {
                    return $override;
                }
            }

            $spec = $catalog->pluginSpec($pluginClass);

            return $spec === null || $spec->activation === Activation::Auto;
        }

        return true;
    }
}
