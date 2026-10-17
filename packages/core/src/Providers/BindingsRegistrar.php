<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Configuration\ObservabilityConfig;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\CoreModule;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Database\WriteScope;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Http\Middleware\VaulterHttpOperationMap;
use Vaulter\Http\Middleware\VaulterThrottledRouteMap;
use Vaulter\Idempotency\IdempotencyClaim;
use Vaulter\Idempotency\IdempotencyOutcome;
use Vaulter\Idempotency\IdempotencyStore;
use Vaulter\Modules\ModuleBindings;
use Vaulter\Observability\NullMetricsRecorder;
use Vaulter\Observability\NullTraceRecorder;
use Vaulter\Operations\ModuleReadiness;
use Vaulter\Operations\OperationClaim;
use Vaulter\Operations\OperationRunner;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\EnumCaseAttributeResolver;
use Vaulter\Tenancy\TenancyBypass;

/**
 * The container wiring of the core module ({@see CoreModule}): config, database, ids, tenancy and
 * observability. The drive slices are wired by the drive's own registrars.
 */
#[Internal]
final class BindingsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->singleton(VaulterConfig::class);
        $app->singleton(DatabaseConfig::class);
        $app->singleton(IdsConfig::class);
        $app->singleton(TenancyConfig::class);
        $app->scoped(TenancyBypass::class);
        $app->scoped(TenantResolver::class, static function (Application $app): TenantResolver {
            $resolver = $app->make($app->make(TenancyConfig::class)->resolver());
            if (!$resolver instanceof TenantResolver) {
                throw new TenancyMisconfiguredException('TenantResolver binding returned an invalid object.');
            }

            return $resolver;
        });
        $app->singleton(RoutesConfig::class);
        $app->singleton(VaulterHttpOperationMap::class);
        $app->singleton(VaulterThrottledRouteMap::class);
        $app->singleton(ObservabilityConfig::class);
        $app->singletonIf(ConfigNormalizer::class);
        $app->singleton(ConfigValidator::class);

        $app->singleton(VaulterDatabase::class);
        // One runner per process: it counts the writes inside its root transaction (nesting).
        $app->scoped(OperationRunner::class);
        $app->singleton(ModuleReadiness::class);
        // Stage 4 of the pipeline (D25): scoped with the runner and the outcome the HTTP middleware reads.
        $app->scoped(OperationClaim::class, IdempotencyClaim::class);
        $app->scoped(IdempotencyOutcome::class);
        $app->singleton(IdempotencyStore::class);
        $app->scoped(WriteScope::class);
        $app->singleton(IdStrategyResolver::class);

        // Container-owned, scoped (review): the enum-attribute
        // reflection cache used to be a process-wide `static $cache`, a literal
        // E3.3 violation (global state outside the facade). Binding it here means
        // the cache's lifecycle follows the container instance instead of the
        // class — and scoped() (not singleton(), see DriveReadRepository right
        // below) is what actually resets it between requests/jobs on Octane/queue
        // workers, since singleton() persists for the whole worker process just
        // like the removed static cache did.
        $app->scoped(EnumCaseAttributeResolver::class);

        $app->singleton(
            MetricsRecorder::class,
            static function (Application $app): MetricsRecorder {

                $settings = $app->make(ObservabilityConfig::class);

                if (!$settings->enabled()) {
                    return new NullMetricsRecorder;
                }

                $class = $settings->recorder();

                if (!is_string($class) || $class === '' || !class_exists($class)) {
                    self::logInvalidObservabilityRecorder(reason: 'missing_or_unknown_class', class: is_string($class) ? $class : null);

                    return new NullMetricsRecorder;
                }

                if (!is_a($class, MetricsRecorder::class, true)) {
                    self::logInvalidObservabilityRecorder(reason: 'does_not_implement_contract', class: $class);

                    return new NullMetricsRecorder;
                }

                $recorder = $app->make($class);

                if (!$recorder instanceof MetricsRecorder) {
                    self::logInvalidObservabilityRecorder(reason: 'container_returned_unexpected_type', class: $class);

                    return new NullMetricsRecorder;
                }

                return $recorder;
            },
        );

        $app->singleton(
            TraceRecorder::class,
            static function (Application $app): TraceRecorder {

                $settings = $app->make(ObservabilityConfig::class);

                if (!$settings->tracingEnabled()) {
                    return new NullTraceRecorder;
                }

                $class = $settings->tracingRecorder();

                if (!is_string($class) || $class === '' || !class_exists($class)) {
                    self::logInvalidTraceRecorder(reason: 'missing_or_unknown_class', class: is_string($class) ? $class : null);

                    return new NullTraceRecorder;
                }

                if (!is_a($class, TraceRecorder::class, true)) {
                    self::logInvalidTraceRecorder(reason: 'does_not_implement_contract', class: $class);

                    return new NullTraceRecorder;
                }

                $recorder = $app->make($class);

                if (!$recorder instanceof TraceRecorder) {
                    self::logInvalidTraceRecorder(reason: 'container_returned_unexpected_type', class: $class);

                    return new NullTraceRecorder;
                }

                return $recorder;
            },
        );
    }

    public function boot(Application $app): void {}

    private static function logInvalidObservabilityRecorder(string $reason, ?string $class): void
    {
        Log::warning('Vaulter observability recorder misconfigured; using NullMetricsRecorder.', [
            'reason' => $reason,
            'recorder' => $class,
        ]);
    }

    private static function logInvalidTraceRecorder(string $reason, ?string $class): void
    {
        Log::warning('Vaulter tracing recorder misconfigured; using NullTraceRecorder.', [
            'reason' => $reason,
            'recorder' => $class,
        ]);
    }
}
