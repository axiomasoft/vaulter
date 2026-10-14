<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\SlotKind;
use Vaulter\Modules\VaulterModule;

/** Defines the Greeter capability and the Step handler slot; contributes the third step itself. */
final class HostModule implements VaulterModule
{
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

    public function register(ModuleBuilder $module): void
    {
        $module
            ->defineCapability(Greeter::class, Cardinality::RequiredSingle)
            ->defineSlot(Step::class, SlotKind::Handler)
            ->slot(Step::class, ThirdStep::class, 'host.third', priority: 30, key: 'third')
            ->schema(__DIR__.'/migrations/host');
    }
}
