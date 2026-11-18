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

/** Opt-in: contributes the second step only when `vaulter.modules.optional` is true. */
final class OptionalModule implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithModuleRuntime, WithSchema, WithSlots
{
    use NativeDeclarations;

    public function id(): ModuleId
    {
        return new ModuleId('optional');
    }

    public function version(): string
    {
        return '1.0';
    }

    public function activation(): Activation
    {
        return Activation::OptIn;
    }

    public function requires(): array
    {
        return ['host' => '^1.0'];
    }

    public function declare(ModuleBuilder $module): void
    {
        $module
            ->slot(Step::class, SecondStep::class, 'optional.second', priority: 20, key: 'second')
            ->schema(__DIR__.'/migrations/optional');
    }
}
