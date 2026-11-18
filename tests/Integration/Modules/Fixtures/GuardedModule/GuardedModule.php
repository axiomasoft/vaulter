<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\GuardedModule;

use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Vaulter\Modules\Activation;
use Vaulter\Modules\GuardsRemoval;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\WithModuleRuntime;
use Vaulter\Tests\Modules\Fixtures\NativeDeclarations;

/** A module that owns one table and guards its own removal ({@see GuardedRemovalGuard}): the subject of the uninstall guard tests. */
final class GuardedModule implements ExtensionPlugin, GuardsRemoval, WithCapabilities, WithConfiguration, WithModuleRuntime, WithSchema, WithSlots
{
    use NativeDeclarations;

    public static function removalGuard(): string
    {
        return GuardedRemovalGuard::class;
    }

    public function id(): ModuleId
    {
        return new ModuleId('guarded');
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
        return ['core' => '^1.0'];
    }

    public function declare(ModuleBuilder $module): void
    {
        $module->schema(__DIR__.'/migrations');
    }
}
