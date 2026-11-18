<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Collaboration;

use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\State\PendingStateDrainer;
use Vaulter\Documents\Tests\Fixtures\State\FakePendingStateDrainer;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\WithModuleRuntime;

final class AcmeModule implements ExtensionPlugin, WithModuleRuntime, WithSlots
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
        return new ModuleId('acme');
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
        return ['documents' => '^1.0'];
    }

    public function slotContributions(): array
    {
        return [
            ['slot' => DocumentType::class, 'implementation' => AcmeType::class, 'id' => 'acme.type', 'key' => 'acme/ot'],
            ['slot' => CollaborationProtocolDriver::class, 'implementation' => AcmeDriver::class, 'id' => 'acme.protocol', 'key' => 'acme-ot'],
            ['slot' => PendingStateDrainer::class, 'implementation' => AcmePendingStateDrainer::class, 'id' => 'acme.drainer', 'key' => 'acme-ot'],
            ['slot' => PendingStateDrainer::class, 'implementation' => FakePendingStateDrainer::class, 'id' => 'acme.fixture.yjs-drainer', 'key' => 'yjs'],
        ];
    }
}
