<?php

declare(strict_types=1);

namespace Vaulter\Filament;

use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Filament\Configuration\FilamentOptions;
use Vaulter\Filament\Providers\BindingsRegistrar;
use Vaulter\Modules\Activation;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\WithModuleRuntime;

/** Native code plugin for the filament package. */
#[Api(since: '1.0')]
final class FilamentModule implements ExtensionPlugin, WithConfiguration, WithModuleRuntime
{
    use Configurable;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'vaulter.filament', version: '1.0.0', requires: ['vaulter.drive'], resourceRoot: dirname(__DIR__).'/resources');
    }

    public function register(PluginRegistrar $registrar): void {}

    public function configRoot(): string
    {
        return 'vaulter-filament';
    }

    public function defaultConfigPath(): string
    {
        return dirname(__DIR__).'/config/vaulter-filament.php';
    }

    public function optionsClass(): string
    {
        return FilamentOptions::class;
    }

    public function moduleRuntime(): PluginModuleSpec
    {
        return new PluginModuleSpec(plugin: self::class, activation: Activation::Auto,
            bindings: [BindingsRegistrar::class],
        );
    }
}
