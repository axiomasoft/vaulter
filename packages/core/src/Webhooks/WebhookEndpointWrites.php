<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Webhooks\WebhookEndpointNotFoundException;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Tenancy\TenancyBoundary;
use Vaulter\ValueObjects\Operations\ScopeRef;
use Vaulter\ValueObjects\Operations\TenantRef;

/**
 * The webhook endpoint management writes (`webhooks.endpoints.<verb>`) as operations of the
 * webhooks module: the request's user acts, the `manage` ability on endpoints is checked inside
 * the operation's transaction, and the endpoint (or the endpoint collection) is the scope.
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
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    public function write(Request $request, string $verb, ?string $endpoint, Closure $attempt): mixed
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
        });
    }
}
