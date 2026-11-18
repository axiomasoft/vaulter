<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Content\Fixtures;

use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\WithModuleRuntime;

/** A module that vetoes downloads, as a quarantine package would: two guards, ahead of the built-in one, the second may throw. */
final class DownloadGuardModule implements ExtensionPlugin, WithModuleRuntime, WithSlots
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

    public function id(): ModuleId
    {
        return new ModuleId('download-guards');
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
        return ['core' => '^1.0', 'drive' => '^1.0'];
    }

    public function slotContributions(): array
    {
        return [
            ['slot' => DownloadGuard::class, 'implementation' => FirstDownloadGuard::class, 'id' => 'fixture.guard.first', 'priority' => -20],
            ['slot' => DownloadGuard::class, 'implementation' => SecondDownloadGuard::class, 'id' => 'fixture.guard.second', 'priority' => -10],
        ];
    }
}
