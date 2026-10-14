<?php

declare(strict_types=1);

namespace Vaulter;

use Vaulter\Console\CommandsReferenceCommand;
use Vaulter\Console\ConfigReferenceCommand;
use Vaulter\Console\DoctorCommand;
use Vaulter\Console\ErrorsReferenceCommand;
use Vaulter\Console\EventsReferenceCommand;
use Vaulter\Console\InstallCommand;
use Vaulter\Console\ModulesCommand;
use Vaulter\Console\ModuleUninstallCommand;
use Vaulter\Console\PruneIdempotencyKeysCommand;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\Checks\CacheCheck;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Doctor\Checks\DatabaseConnectionCheck;
use Vaulter\Doctor\Checks\ModuleCacheFreshnessCheck;
use Vaulter\Doctor\Checks\QueueCheck;
use Vaulter\Doctor\Checks\SchedulerCheck;
use Vaulter\Doctor\Checks\SchemaIntegrityCheck;
use Vaulter\Doctor\Checks\SchemaKeyTypesCheck;
use Vaulter\Doctor\Checks\SchemaTablesCheck;
use Vaulter\Doctor\Checks\TenancyCheck;
use Vaulter\Events\EventSink;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Http\Problems\CoreProblemCodes;
use Vaulter\Idempotency\IdempotencySchedule;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\SlotKind;
use Vaulter\Modules\VaulterModule;
use Vaulter\Observability\TelemetryMiddleware;
use Vaulter\Operations\OperationMiddleware;
use Vaulter\Providers\BindingsRegistrar;
use Vaulter\Providers\EventsRegistrar;

/**
 * The kernel's own module (ADR-0027): the `vaulter` config root, the schema of every create-migration
 * still shipped by core until P4 splits them (D36), the kernel commands and doctor checks, the
 * DoctorCheck slot every module contributes its checks to, the OperationMiddleware slot of the
 * operation pipeline with its telemetry middleware (ADR-0028) and the EventSink slot of the event
 * recorder (D38).
 */
final class CoreModule implements VaulterModule
{
    /** @var array<string, class-string<DoctorCheck>> doctor key => check */
    private const array DOCTOR_CHECKS = [
        'core.database.connection' => DatabaseConnectionCheck::class,
        'core.schema.tables' => SchemaTablesCheck::class,
        'core.schema.key_types' => SchemaKeyTypesCheck::class,
        'core.schema.integrity' => SchemaIntegrityCheck::class,
        'core.config.valid' => ConfigValidCheck::class,
        'core.tenancy' => TenancyCheck::class,
        'core.queue' => QueueCheck::class,
        'core.scheduler' => SchedulerCheck::class,
        'core.cache' => CacheCheck::class,
        'core.modules.cache' => ModuleCacheFreshnessCheck::class,
    ];

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
            )
            ->defineSlot(DoctorCheck::class, SlotKind::Handler, Cardinality::Many)
            ->defineSlot(OperationMiddleware::class, SlotKind::OperationMiddleware, Cardinality::Many)
            ->defineSlot(EventSink::class, SlotKind::EventSink, Cardinality::Many)
            ->slot(OperationMiddleware::class, TelemetryMiddleware::class, 'core.telemetry');

        foreach (self::DOCTOR_CHECKS as $key => $check) {
            $module->slot(DoctorCheck::class, $check, $key, key: $key);
        }
    }
}
