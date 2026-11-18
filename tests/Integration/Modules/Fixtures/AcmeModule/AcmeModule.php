<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule;

use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Drive\Contracts\NodeSnapshotContributor;
use Vaulter\Events\EventSink;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\SlotKind;
use Vaulter\Modules\WithModuleRuntime;
use Vaulter\Operations\OperationMiddleware;

/**
 * A third-party module written against the public module surface only: it owns a config root, a table,
 * a route, a command, a listener and container wiring, defines a slot of its own (a Restriction one, which the
 * kernel has no built-in for) and contributes to the core and drive slots. Opt-in, like every module that
 * opens a new surface.
 */
final class AcmeModule implements ExtensionPlugin, WithConfiguration, WithModuleRuntime, WithSchema, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use OwnsSchema;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'vaulter.acme', version: '1.0.0', requires: ['vaulter.core', 'vaulter.drive']);
    }

    public function register(PluginRegistrar $registrar): void {}

    public function configRoot(): string
    {
        return 'vaulter-acme';
    }

    public function defaultConfigPath(): ?string
    {
        return __DIR__.'/config.php';
    }

    public function migrationPath(): ?string
    {
        return __DIR__.'/migrations';
    }

    public function moduleRuntime(): PluginModuleSpec
    {
        return new PluginModuleSpec(plugin: self::class, activation: Activation::OptIn,
            routes: [__DIR__.'/routes.php'], operations: [AcmeOperationPolicies::class],
            commands: [AcmeCommand::class], listeners: [['event' => AcmePinged::class, 'listener' => AcmeListener::class]],
            bindings: [AcmeBindings::class]);
    }

    public function definedSlots(): array
    {
        return [AcmeRestriction::class => ['kind' => SlotKind::Restriction->value, 'cardinality' => Cardinality::Many->value]];
    }

    public function slotContributions(): array
    {
        return [
            ['slot' => AcmeRestriction::class, 'implementation' => AcmeNarrowing::class, 'id' => 'acme.narrowing'],
            ['slot' => OperationMiddleware::class, 'implementation' => AcmeMiddleware::class, 'id' => 'acme.middleware', 'priority' => 10],
            ['slot' => EventSink::class, 'implementation' => AcmeEventSink::class, 'id' => 'acme.sink', 'priority' => 50],
            ['slot' => OutboxConsumer::class, 'implementation' => AcmeOutboxConsumer::class, 'id' => AcmeOutboxConsumer::ID],
            ['slot' => DoctorCheck::class, 'implementation' => AcmeDoctorCheck::class, 'id' => 'acme.endpoint', 'key' => 'acme.endpoint'],
            ['slot' => DownloadGuard::class, 'implementation' => AcmeDownloadGuard::class, 'id' => 'acme.download-guard', 'priority' => -5],
            ['slot' => NodeSnapshotContributor::class, 'implementation' => AcmeSnapshotContributor::class, 'id' => 'acme.snapshot'],
        ];
    }
}
