<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\WithModuleRuntime;

/** Provides the Greeter and contributes the first step. */
final class PluginModule implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithModuleRuntime, WithSchema, WithSlots
{
    use NativeDeclarations;

    public function id(): ModuleId
    {
        return new ModuleId('plugin');
    }

    public function version(): string
    {
        return '1.0';
    }

    public function activation(): Activation
    {
        return Activation::Auto;
    }

    public function requires(): array
    {
        return ['host' => '^1.0'];
    }

    public function declare(ModuleBuilder $module): void
    {
        $module
            ->provides(Greeter::class, PlainGreeter::class)
            ->slot(Step::class, FirstStep::class, 'plugin.first', priority: 10, key: 'first');
    }
}
