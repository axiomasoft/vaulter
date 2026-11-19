<?php

declare(strict_types=1);

namespace Vaulter;

use LogicException;
use Override;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality as PluginCardinality;
use Packman\Plugins\Enums\Contributions\SlotKind as PluginSlotKind;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Configuration\VaulterOptions;
use Vaulter\Console\CommandsReferenceCommand;
use Vaulter\Console\ConfigReferenceCommand;
use Vaulter\Console\DoctorCommand;
use Vaulter\Console\ErrorsReferenceCommand;
use Vaulter\Console\EventsReferenceCommand;
use Vaulter\Console\InstallCommand;
use Vaulter\Console\ModulesCommand;
use Vaulter\Console\ModuleUninstallCommand;
use Vaulter\Console\OperationsReferenceCommand;
use Vaulter\Console\PruneIdempotencyKeysCommand;
use Vaulter\Console\PruneOutboxCommand;
use Vaulter\Console\RelayCommand;
use Vaulter\Console\RelayReplayCommand;
use Vaulter\Contracts\Routing\RouteLabelSource;
use Vaulter\Contracts\Security\ProductionInvariant;
use Vaulter\Doctor\Checks\CacheCheck;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Doctor\Checks\DatabaseConnectionCheck;
use Vaulter\Doctor\Checks\ModuleCacheFreshnessCheck;
use Vaulter\Doctor\Checks\OutboxConsumerGapCheck;
use Vaulter\Doctor\Checks\OutboxDeadLettersCheck;
use Vaulter\Doctor\Checks\OutboxLagCheck;
use Vaulter\Doctor\Checks\OutboxLeasesCheck;
use Vaulter\Doctor\Checks\OutboxSettleWindowCheck;
use Vaulter\Doctor\Checks\ProductionInvariantsCheck;
use Vaulter\Doctor\Checks\QueueCheck;
use Vaulter\Doctor\Checks\SchedulerCheck;
use Vaulter\Doctor\Checks\SchemaKeyTypesCheck;
use Vaulter\Doctor\Checks\SchemaTablesCheck;
use Vaulter\Doctor\Checks\TenancyCheck;
use Vaulter\Errors\VaulterProblemDefinitions;
use Vaulter\Events\EventSink;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Events\Outbox\OutboxSchedule;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Http\Problems\CoreProblemCodes;
use Vaulter\Idempotency\IdempotencySchedule;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\WithModuleRuntime;
use Vaulter\Observability\TelemetryMiddleware;
use Vaulter\Operations\OperationMiddleware;
use Vaulter\Providers\BindingsRegistrar;
use Vaulter\Providers\EventsRegistrar;
use Vaulter\Schema\CoreSchemaSets;
use Vaulter\Schema\KernelSchema;
use Vaulter\Security\TenancyInvariant;

/**
 * The kernel's own Packman extension plugin (`vaulter.core`), owning the `vaulter` config root,
 * core outbox/idempotency schema sets, diagnostic checks, error definitions, and extension slots.
 */
#[Api(since: '1.0')]
final class CoreModule implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithDiagnostics, WithModuleRuntime, WithSchema, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesCapabilities;
    use ProvidesDiagnostics;

    /** @var array<string, class-string<DoctorCheck>> doctor key => check */
    private const array DOCTOR_CHECKS = [
        'core.database.connection' => DatabaseConnectionCheck::class,
        'core.schema.tables' => SchemaTablesCheck::class,
        'core.schema.key_types' => SchemaKeyTypesCheck::class,
        'core.config.valid' => ConfigValidCheck::class,
        'core.tenancy' => TenancyCheck::class,
        'core.queue' => QueueCheck::class,
        'core.scheduler' => SchedulerCheck::class,
        'core.cache' => CacheCheck::class,
        'core.modules.cache' => ModuleCacheFreshnessCheck::class,
        'core.outbox.lag' => OutboxLagCheck::class,
        'core.outbox.dead' => OutboxDeadLettersCheck::class,
        'core.outbox.leases' => OutboxLeasesCheck::class,
        'core.outbox.consumer_gaps' => OutboxConsumerGapCheck::class,
        'core.outbox.settle_window' => OutboxSettleWindowCheck::class,
        'core.production_invariants' => ProductionInvariantsCheck::class,
    ];

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'vaulter.core',
            version: '1.0.0',
            resourceRoot: ($root = realpath(dirname(__DIR__).'/resources')) !== false ? $root : throw new LogicException('Vaulter resource root is unavailable.'),
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    #[Override]
    public function configRoot(): string
    {
        return 'vaulter';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__).'/config/vaulter.php';
    }

    #[Override]
    public function optionsClass(): string
    {
        return VaulterOptions::class;
    }

    #[Override]
    public function tablePrefix(): string
    {
        return 'v_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return ['outbox', 'outbox_consumers', 'outbox_deliveries', 'idempotency_keys'];
    }

    #[Override]
    public function migrationPath(): string
    {
        return __DIR__.'/../database/migrations';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [CoreSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaComponents(): array
    {
        return [KernelSchema::class];
    }

    /** @return array<class-string, array{kind: string, cardinality?: string, shared?: bool}> */
    #[Override]
    public function definedSlots(): array
    {
        return [
            DoctorCheck::class => ['kind' => PluginSlotKind::Contributor->value, 'cardinality' => PluginCardinality::Many->value, 'shared' => true],
            OperationMiddleware::class => ['kind' => PluginSlotKind::OperationMiddleware->value, 'cardinality' => PluginCardinality::Many->value],
            EventSink::class => ['kind' => PluginSlotKind::EventSink->value, 'cardinality' => PluginCardinality::Many->value],
            OutboxConsumer::class => ['kind' => PluginSlotKind::OutboxConsumer->value, 'cardinality' => PluginCardinality::Many->value],
            RouteLabelSource::class => ['kind' => PluginSlotKind::Contributor->value, 'cardinality' => PluginCardinality::Many->value],
            ProductionInvariant::class => ['kind' => PluginSlotKind::Handler->value, 'cardinality' => PluginCardinality::Many->value],
        ];
    }

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    #[Override]
    public function slotContributions(): array
    {
        $contributions = [
            ['slot' => ProductionInvariant::class, 'id' => 'core.tenancy', 'implementation' => TenancyInvariant::class, 'priority' => 0, 'key' => 'core.tenancy'],
            ['slot' => OperationMiddleware::class, 'id' => 'core.telemetry', 'implementation' => TelemetryMiddleware::class],
        ];

        foreach (self::DOCTOR_CHECKS as $key => $check) {
            $contributions[] = ['slot' => DoctorCheck::class, 'id' => $key, 'implementation' => $check, 'key' => $key];
        }

        return $contributions;
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return array_values(self::DOCTOR_CHECKS);
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [VaulterProblemDefinitions::class];
    }

    /** @return class-string<KernelSchema> */
    #[Internal]
    public static function ownedSchema(): string
    {
        return KernelSchema::class;
    }

    public function moduleRuntime(): PluginModuleSpec
    {
        return new PluginModuleSpec(
            plugin: self::class,
            translations: ['vaulter' => dirname(__DIR__).'/resources/lang'],
            commands: [InstallCommand::class, DoctorCommand::class, ModulesCommand::class,
                ModuleUninstallCommand::class, EventsReferenceCommand::class, ErrorsReferenceCommand::class,
                OperationsReferenceCommand::class, ConfigReferenceCommand::class, CommandsReferenceCommand::class,
                PruneIdempotencyKeysCommand::class, RelayCommand::class, RelayReplayCommand::class, PruneOutboxCommand::class],
            schedules: [IdempotencySchedule::class, OutboxSchedule::class],
            routeMiddleware: [ReadIdempotencyKey::ALIAS => ReadIdempotencyKey::class],
            bindings: [BindingsRegistrar::class, EventsRegistrar::class],
            problems: [CoreProblemCodes::class],
        );
    }
}
