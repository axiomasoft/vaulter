<?php

declare(strict_types=1);

namespace Vaulter;

use Vaulter\Attributes\Internal;
use Vaulter\Console\CommandsReferenceCommand;
use Vaulter\Console\ConfigReferenceCommand;
use Vaulter\Console\DoctorCommand;
use Vaulter\Console\ErrorsReferenceCommand;
use Vaulter\Console\EventsReferenceCommand;
use Vaulter\Console\InstallCommand;
use Vaulter\Console\ModulesCommand;
use Vaulter\Console\ModuleUninstallCommand;
use Vaulter\Console\PruneIdempotencyKeysCommand;
use Vaulter\Console\PruneOutboxCommand;
use Vaulter\Console\RelayCommand;
use Vaulter\Console\RelayReplayCommand;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\Checks\CacheCheck;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Doctor\Checks\DatabaseConnectionCheck;
use Vaulter\Doctor\Checks\ModuleCacheFreshnessCheck;
use Vaulter\Doctor\Checks\OutboxConsumerGapCheck;
use Vaulter\Doctor\Checks\OutboxLagCheck;
use Vaulter\Doctor\Checks\OutboxLeasesCheck;
use Vaulter\Doctor\Checks\OutboxSettleWindowCheck;
use Vaulter\Doctor\Checks\QueueCheck;
use Vaulter\Doctor\Checks\SchedulerCheck;
use Vaulter\Doctor\Checks\SchemaKeyTypesCheck;
use Vaulter\Doctor\Checks\SchemaTablesCheck;
use Vaulter\Doctor\Checks\TenancyCheck;
use Vaulter\Events\EventSink;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Events\Outbox\OutboxSchedule;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Http\Problems\CoreProblemCodes;
use Vaulter\Idempotency\IdempotencySchedule;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\OwnsSchema;
use Vaulter\Modules\SlotKind;
use Vaulter\Modules\VaulterModule;
use Vaulter\Observability\TelemetryMiddleware;
use Vaulter\Operations\OperationMiddleware;
use Vaulter\Providers\BindingsRegistrar;
use Vaulter\Providers\EventsRegistrar;
use Vaulter\Schema\KernelSchema;

/**
 * The kernel's own module (ADR-0027): the `vaulter` config root, the schema of every create-migration
 * still shipped by core until P4 splits them (D36), the kernel commands and doctor checks, the
 * DoctorCheck slot every module contributes its checks to, the OperationMiddleware slot of the
 * operation pipeline with its telemetry middleware (ADR-0028), the EventSink slot of the event
 * recorder (D38) and the outbox: its OutboxConsumer slot, relay, replay and retention commands, schedule
 * and doctor checks (D29).
 */
#[Internal]
final class CoreModule implements OwnsSchema, VaulterModule
{
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
        'core.outbox.leases' => OutboxLeasesCheck::class,
        'core.outbox.consumer_gaps' => OutboxConsumerGapCheck::class,
        'core.outbox.settle_window' => OutboxSettleWindowCheck::class,
    ];

    public static function ownedSchema(): string
    {
        return KernelSchema::class;
    }

    public function id(): ModuleId
    {
        return new ModuleId('core');
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
        return [];
    }

    public function register(ModuleBuilder $module): void
    {
        $module
            ->config('vaulter', dirname(__DIR__).'/config/vaulter.php')
            ->schema(dirname(__DIR__).'/database/migrations')
            ->bindings(BindingsRegistrar::class)
            ->bindings(EventsRegistrar::class)
            ->problems(CoreProblemCodes::class)
            ->routeMiddleware(ReadIdempotencyKey::ALIAS, ReadIdempotencyKey::class)
            ->schedule(IdempotencySchedule::class)
            ->schedule(OutboxSchedule::class)
            ->commands(
                InstallCommand::class,
                DoctorCommand::class,
                ModulesCommand::class,
                ModuleUninstallCommand::class,
                EventsReferenceCommand::class,
                ErrorsReferenceCommand::class,
                ConfigReferenceCommand::class,
                CommandsReferenceCommand::class,
                PruneIdempotencyKeysCommand::class,
                RelayCommand::class,
                RelayReplayCommand::class,
                PruneOutboxCommand::class,
            )
            ->defineSlot(DoctorCheck::class, SlotKind::Handler, Cardinality::Many)
            ->defineSlot(OperationMiddleware::class, SlotKind::OperationMiddleware, Cardinality::Many)
            ->defineSlot(EventSink::class, SlotKind::EventSink, Cardinality::Many)
            ->defineSlot(OutboxConsumer::class, SlotKind::OutboxConsumer, Cardinality::Many)
            ->slot(OperationMiddleware::class, TelemetryMiddleware::class, 'core.telemetry');

        foreach (self::DOCTOR_CHECKS as $key => $check) {
            $module->slot(DoctorCheck::class, $check, $key, key: $key);
        }
    }
}
