<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Resources\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Http\Support\Preconditions;
use Vaulter\Webhooks\Data\WebhookEndpointSnapshot;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;

/**
 * What a conditional write of an endpoint (update, deactivate, rotate-secret, delete) expects (CC2): the version
 * in the endpoint's tag.
 *
 * As for the other versioned aggregates, the If-Match header is compared only after the caller was seen to hold the
 * `manage` ability (403) and the endpoint was found (404); then it is required (428 without it or with `*`) and must
 * equal the current tag (412 stale_write). The version it names is what the operation compares again under the
 * endpoint's row lock (CC1), so this check only answers earlier.
 *
 * On a route of a receipt operation a retry under its Idempotency-Key repeats the If-Match of the first request,
 * stale once that one committed (a deleted endpoint is gone altogether): with a key the write gets a version no row
 * holds, and the operation answers from the receipt, or refuses — and stores — the stale write. Without a key the
 * refusal stands.
 */
#[Internal]
final readonly class EndpointPreconditions
{
    public function __construct(private WebhookEndpointReadRepository $endpoints) {}

    public function expectedVersion(Request $request, string $endpoint): int
    {
        // Who may not manage endpoints learns nothing from a 404, a 428 or a 412.
        Gate::authorize('manage', WebhookEndpoint::class);

        $held = null;
        try {
            $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);
            $snapshot = WebhookEndpointSnapshot::of($model);
            $held = $snapshot->version;
            if (!Preconditions::matches($request, WebhookEndpointResource::tag($snapshot))) {
                throw StaleWriteException::of(WebhookEndpoint::AGGREGATE, $snapshot->id);
            }

            return $held;
        } catch (StaleWriteException|WebhookEndpointNotFoundException $refusal) {
            if (ReadIdempotencyKey::key($request) === null) {
                throw $refusal;
            }

            return $held === PHP_INT_MAX ? PHP_INT_MAX - 1 : PHP_INT_MAX;
        }
    }
}
