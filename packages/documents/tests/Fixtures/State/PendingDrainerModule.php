<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\State;

use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Documents\State\PendingStateDrainer;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\WithModuleRuntime;

/**
 * A module providing the documents' PendingStateDrainer capability the way the collaboration module will, from config:
 * a test sets {@see self::CONFIG_KEY} to a drainer class, and without it no drainer is available.
 */
final class PendingDrainerModule implements ExtensionPlugin, WithModuleRuntime, WithSlots
{
    use ContributesToSlots;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'vaulter.'.$this->id()->value, version: '1.0.0', requires: array_map(static fn (string $id): string => 'vaulter.'.$id, array_keys($this->requires())));
    }

    public function register(PluginRegistrar $registrar): void {}

    public function moduleRuntime(): PluginModuleSpec
    {
        return new PluginModuleSpec(plugin: self::class, protocolVersion: $this->version(), requires: $this->requires());
    }

    public const string CONFIG_KEY = 'vaulter-documents-testing.pending_drainer';

    public function id(): ModuleId
    {
        return new ModuleId('pending-drainer-fixture');
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
        return ['core' => '^1.0', 'drive' => '^1.0', 'documents' => '^1.0'];
    }

    public function slotContributions(): array
    {
        $class = config(self::CONFIG_KEY);

        return is_string($class) ? [['slot' => PendingStateDrainer::class, 'implementation' => $class, 'id' => 'fixture.pending.yjs', 'key' => 'yjs']] : [];
    }
}
