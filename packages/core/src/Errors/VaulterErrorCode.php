<?php

declare(strict_types=1);

namespace Vaulter\Errors;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Canonical Packman error codes owned by `axiomasoft/vaulter-core`.
 */
#[Api(since: '1.0')]
enum VaulterErrorCode: string implements ErrorCode
{
    case ActorRequired = 'vaulter.actor_required';
    case ConcurrentUpdate = 'vaulter.concurrent_update';
    case DependencyUnavailable = 'vaulter.dependency_unavailable';
    case DuplicateRegistration = 'vaulter.duplicate_registration';
    case ExtensionFailed = 'vaulter.extension_failed';
    case ForeignKeyViolation = 'vaulter.foreign_key_violation';
    case IdempotencyKeyExpired = 'vaulter.idempotency_key_expired';
    case IdempotencyKeyReused = 'vaulter.idempotency_key_reused';
    case InsecureProductionConfiguration = 'vaulter.insecure_production_configuration';
    case InternalError = 'vaulter.internal_error';
    case InvalidConfiguration = 'vaulter.invalid_configuration';
    case LeaseLost = 'vaulter.lease_lost';
    case MethodNotAllowed = 'vaulter.method_not_allowed';
    case MissingExtension = 'vaulter.missing_extension';
    case MissingPackage = 'vaulter.missing_package';
    case ModuleGraphInvalid = 'vaulter.module_graph_invalid';
    case ModuleNotReady = 'vaulter.module_not_ready';
    case NodeSequenceExhausted = 'vaulter.node_sequence_exhausted';
    case OperationInProgress = 'vaulter.operation_in_progress';
    case PayloadTooLarge = 'vaulter.payload_too_large';
    case PermissionDenied = 'vaulter.permission_denied';
    case PreconditionRequired = 'vaulter.precondition_required';
    case RateLimited = 'vaulter.rate_limited';
    case RegistryFrozen = 'vaulter.registry_frozen';
    case ReplayedProblem = 'vaulter.replayed_problem';
    case RouteNotFound = 'vaulter.route_not_found';
    case StaleWrite = 'vaulter.stale_write';
    case TenancyMisconfigured = 'vaulter.tenancy_misconfigured';
    case UnknownIdStrategy = 'vaulter.unknown_id_strategy';
    case UnknownOperation = 'vaulter.unknown_operation';
    case ValidationFailed = 'vaulter.validation_failed';
    case WeakEtagNotAllowed = 'vaulter.weak_etag_not_allowed';
    case WriteOutsideOperation = 'vaulter.write_outside_operation';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}
