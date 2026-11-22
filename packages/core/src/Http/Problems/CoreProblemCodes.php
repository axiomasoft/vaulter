<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Packman\Attributes\Internal;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Exceptions\Database\ConcurrentUpdateException;
use Vaulter\Exceptions\Database\WriteOutsideOperationException;
use Vaulter\Exceptions\Http\PreconditionRequiredException;
use Vaulter\Exceptions\Http\WeakEtagNotAllowedException;
use Vaulter\Exceptions\Idempotency\IdempotencyKeyExpiredException;
use Vaulter\Exceptions\Idempotency\IdempotencyKeyReusedException;
use Vaulter\Exceptions\Idempotency\OperationInProgressException;
use Vaulter\Exceptions\Modules\ModuleNotReadyException;
use Vaulter\Exceptions\Registry\RegistryFrozenException;
use Vaulter\Exceptions\Support\InvalidExtensionResult;
use Vaulter\Modules\ModuleGraphException;

/**
 * The problem codes of the kernel (architecture#errors): the HTTP frame, the actor, the transaction, the
 * module graph and idempotency. A stable public contract, so a code is added here, in
 * lang/{en,ru}/problems.php and in the reference docs together.
 */
#[Internal]
final class CoreProblemCodes implements ProblemCodeSource
{
    public function translationNamespace(): string
    {
        return 'vaulter';
    }

    public function codes(): array
    {
        return [
            'actor_required' => new ProblemSpec(401, MissingActorException::class),
            // Exhausted serialization retries: the request is safe to repeat, and never a stored outcome.
            'concurrent_update' => new ProblemSpec(409, ConcurrentUpdateException::class, Retryable::WithBackoff),
            'dependency_unavailable' => new ProblemSpec(503, null, Retryable::WithBackoff),
            // A second registration of a kind, type or exporter at boot: the registry owners raise it with this code.
            'duplicate_registration' => new ProblemSpec(500),
            'extension_failed' => new ProblemSpec(500, InvalidExtensionResult::class),
            'foreign_key_violation' => new ProblemSpec(409),
            'idempotency_key_expired' => new ProblemSpec(410, IdempotencyKeyExpiredException::class),
            'idempotency_key_reused' => new ProblemSpec(422, IdempotencyKeyReusedException::class),
            'internal_error' => new ProblemSpec(500),
            'method_not_allowed' => new ProblemSpec(405, MethodNotAllowedHttpException::class),
            'module_graph_invalid' => new ProblemSpec(503, ModuleGraphException::class),
            'module_not_ready' => new ProblemSpec(503, ModuleNotReadyException::class),
            'operation_in_progress' => new ProblemSpec(409, OperationInProgressException::class, Retryable::WithBackoff),
            'payload_too_large' => new ProblemSpec(413, PostTooLargeException::class, receipt: ReceiptPolicy::Cacheable),
            // The refusal of an ability: the framework's gate denial and the owners' permission exceptions render it.
            'permission_denied' => new ProblemSpec(403, AuthorizationException::class),
            'precondition_required' => new ProblemSpec(428, PreconditionRequiredException::class, Retryable::AfterRefetch),
            'rate_limited' => new ProblemSpec(429, ThrottleRequestsException::class, Retryable::WithBackoff),
            'registry_frozen' => new ProblemSpec(500, RegistryFrozenException::class),
            'route_not_found' => new ProblemSpec(404, NotFoundHttpException::class),
            // The If-Match of a write names a version that is no longer current: deterministic, so a receipt stores it.
            'stale_write' => new ProblemSpec(412, StaleWriteException::class, Retryable::AfterRefetch, ReceiptPolicy::Cacheable),
            'validation_failed' => new ProblemSpec(422, ValidationException::class),
            'weak_etag_not_allowed' => new ProblemSpec(412, WeakEtagNotAllowedException::class, Retryable::AfterRefetch),
            'write_outside_operation' => new ProblemSpec(500, WriteOutsideOperationException::class),
        ];
    }
}
