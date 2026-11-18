<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\ValueObjects\Operations\ScopeRef;
use Vaulter\ValueObjects\Operations\TenantRef;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Idempotency\EndpointReceiptCodec;
use Vaulter\Webhooks\Idempotency\EndpointReplayAuthorizer;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;

/**
 * The webhook endpoint management writes (`webhooks.endpoints.<verb>`) as operations of the
 * webhooks module: the request's user acts, the `manage` ability on endpoints is checked inside
 * the operation's transaction, and the endpoint (or the endpoint collection) is the scope.
 *
 * A write with a `$fingerprint` takes an Idempotency-Key: the receipt is the {@see EndpointReceiptCodec} result, a
 * signing secret in it only as ciphertext, and a replay is authorized again by the `manage` ability. A redelivery takes
 * one too: without a key, a repeat after the delivery finished again is a new redelivery.
 *
 * used by the webhook endpoint controllers
 */
#[Internal]
final readonly class WebhookEndpointWrites
{
    public function __construct(
        private OperationRunner $runner,
        private HttpOperationContextFactory $contexts,
        private TenancyBoundary $tenancy,
        private WebhookEndpointReadRepository $endpoints,
        private Encrypter $encrypter,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T              $attempt
     * @param  array<string, mixed>|null $fingerprint the semantic request of a keyed write: ids, input, the expected version
     * @return T
     */
    public function write(Request $request, string $verb, ?string $endpoint, Closure $attempt, ?array $fingerprint = null): mixed
    {
        if (!$request->user() instanceof Authorizable) {
            // Without a user the ability check answers first, as it always did.
            Gate::authorize('manage', WebhookEndpoint::class);
        }
        try {
            $context = $this->contexts->operation(
                $request,
                $endpoint === null ? new ScopeRef('webhook_endpoints', 'all') : new ScopeRef('webhook_endpoint', $endpoint),
                $this->tenancy->enforced() ? TenantRef::id($this->tenancy->current()) : TenantRef::none(),
            );
        } catch (Throwable $failure) {
            // A malformed id or an unresolved tenant answers after the ability check, as it always did:
            // 403 for who may not manage endpoints, 404 for who may.
            Gate::authorize('manage', WebhookEndpoint::class);
            throw $failure instanceof InvalidArgumentException && $endpoint !== null
                ? WebhookEndpointNotFoundException::forId($endpoint)
                : $failure;
        }

        return $this->runner->write(new OperationSpec('webhooks.endpoints.'.$verb, new ModuleId('webhooks')), $context, static function () use ($attempt): mixed {
            Gate::authorize('manage', WebhookEndpoint::class);

            return $attempt();
        }, $fingerprint === null ? null : new IdempotentWrite(
            codec: new EndpointReceiptCodec($this->encrypter),
            authorizer: new EndpointReplayAuthorizer($this->endpoints, $this->tenancy, $endpoint ?? 'new'),
            fingerprint: $fingerprint,
        ));
    }
}
