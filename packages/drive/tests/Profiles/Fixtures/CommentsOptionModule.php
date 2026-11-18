<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Profiles\Fixtures;

use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Drive\Contracts\Profiles\ProfileOption;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\WithModuleRuntime;

/** A module that owns the comments feature, as the documents module does: it contributes the `comments` option. */
final class CommentsOptionModule implements ExtensionPlugin, WithModuleRuntime, WithSlots
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
        return new ModuleId('comments-owner');
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
            ['slot' => ProfileOption::class, 'implementation' => CommentsOption::class, 'id' => 'fixture.profile.comments', 'key' => CommentsOption::NAME],
        ];
    }
}
