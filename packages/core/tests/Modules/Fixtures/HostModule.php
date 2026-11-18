<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\SlotKind;
use Vaulter\Modules\WithModuleRuntime;

/** Defines the Greeter capability and the Step handler slot; contributes the third step itself. */
final class HostModule implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithModuleRuntime, WithSchema, WithSlots
{
    use NativeDeclarations;

    public function id(): ModuleId
    {
        return new ModuleId('host');
    }

    public function version(): string
    {
        return '1.2';
    }

    public function activation(): Activation
    {
        return Activation::Auto;
    }

    public function requires(): array
    {
        return [];
    }

    public function declare(ModuleBuilder $module): void
    {
        $module
            ->defineCapability(Greeter::class, Cardinality::RequiredSingle)
            ->defineSlot(Step::class, SlotKind::Handler)
            ->slot(Step::class, ThirdStep::class, 'host.third', priority: 30, key: 'third')
            ->schema(__DIR__.'/migrations/host');
    }
}
