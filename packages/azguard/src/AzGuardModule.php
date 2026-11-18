<?php

declare(strict_types=1);

namespace Vaulter\AzGuard;

use Packman\Attributes\Api;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\AzGuard\Configuration\AzGuardOptions;
use Vaulter\AzGuard\Contracts\AzGuardGuard;
use Vaulter\AzGuard\Doctor\AzGuardDependencyCheck;
use Vaulter\AzGuard\Providers\BindingsRegistrar;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Modules\Activation;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\WithModuleRuntime;

/** Native code plugin for the azguard package. */
#[Api(since: '1.0')]
final class AzGuardModule implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithDiagnostics, WithModuleRuntime, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use ProvidesCapabilities;
    use ProvidesDiagnostics;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'vaulter.azguard', version: '1.0.0', requires: ['vaulter.core', 'vaulter.drive'], resourceRoot: dirname(__DIR__).'/resources');
    }

    public function register(PluginRegistrar $registrar): void {}

    public function configRoot(): string
    {
        return 'vaulter-azguard';
    }

    public function defaultConfigPath(): string
    {
        return dirname(__DIR__).'/config/vaulter-azguard.php';
    }

    public function optionsClass(): string
    {
        return AzGuardOptions::class;
    }

    public function moduleRuntime(): PluginModuleSpec
    {
        return new PluginModuleSpec(plugin: self::class, activation: Activation::Auto,
            bindings: [BindingsRegistrar::class],
        );
    }

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    public function slotContributions(): array
    {
        return [
            ['slot' => DoctorCheck::class, 'id' => 'azguard.dependency', 'implementation' => AzGuardDependencyCheck::class, 'key' => 'azguard.dependency'],
            ['slot' => PermissionDriver::class, 'id' => 'azguard.permission', 'implementation' => AzGuardPermissionDriver::class, 'key' => 'azguard'],
        ];
    }

    /** @return list<class-string> */
    public function healthChecks(): array
    {
        return [AzGuardDependencyCheck::class];
    }

    /** @return array<class-string, string> */
    public function definedCapabilities(): array
    {
        return [AzGuardGuard::class => Cardinality::OptionalSingle->value];
    }

    /** @return array<class-string, class-string|array{config: string, default?: class-string|null}> */
    public function providedCapabilities(): array
    {
        return [AzGuardGuard::class => ['config' => 'vaulter-azguard.guard']];
    }
}
