<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationCatalog;
use Vaulter\Testing\Tenancy\MutableTenantResolver;
use Vaulter\Webhooks\Jobs\DeliverWebhook;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;

/**
 * The security matrix of the webhooks' mutating routes: every route of the
 * package × the rows R1–R9 of the audit.
 *
 *   R1 401 without authentication              R6 a system actor without a reason is refused
 *   R2 404 a resource of another tenant        R7 move into a hidden folder → 404
 *   R3 404 without View                        R8 move without Create on the destination → 403
 *   R4 403 with View but without the ability   R9 a replay after the access was revoked is refused, not answered
 *   R5 an expired or revoked share link is refused                from its receipt
 *
 * The webhook admin surface is global: its one gate is the `manage` ability, which answers before any lookup. A row
 * that has no meaning there is `n/a` with its reason; the coverage test holds the route set to the operation catalog and
 * a skipped row of a receipt route red.
 */
afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

/**
 * The routes of the matrix, each row either null (checked) or the reason it does not apply.
 *
 * @return array<string, array<string, string|null>>
 */
function smwhMatrix(): array
{
    $noView = 'the admin surface is global: no per-resource View exists, its only gate is the manage ability (R4), which answers 403 before any lookup — an unknown id included';
    $noShare = 'not a share route: no link with an expiry or a revocation';
    $noSystem = 'the endpoint writes run only under a request\'s user (WebhookEndpointWrites): no PHP entry takes an actor, so a system actor cannot reach them';
    $noMove = 'not a move: the route has no destination';
    $row = static fn (): array => ['R1' => null, 'R2' => null, 'R3' => $noView, 'R4' => null, 'R5' => $noShare, 'R6' => $noSystem, 'R7' => $noMove, 'R8' => $noMove, 'R9' => null];

    return [
        'webhook-endpoints.store' => $row(),
        'webhook-endpoints.update' => $row(),
        'webhook-endpoints.deactivate' => $row(),
        'webhook-endpoints.rotate-secret' => $row(),
        'webhook-endpoints.destroy' => $row(),
        'webhook-deliveries.redeliver' => $row(),
    ];
}

/** @return array<string, array{0: string, 1: string}> the cells that are checked: `route R1` => [route, row] */
function smwhCells(): array
{
    $cells = [];
    foreach (smwhMatrix() as $route => $rows) {
        foreach ($rows as $row => $reason) {
            if ($reason === null) {
                $cells[$route.' '.$row] = [$route, $row];
            }
        }
    }

    return $cells;
}

dataset('webhook matrix', fn (): array => smwhCells());

/**
 * One request of a route against fresh fixtures: an endpoint (of $tenant), and for a redelivery a dead delivery.
 *
 * @return array{method: string, path: string, ghost: string, body: array<string, mixed>, headers: array<string, string>, endpoint: int|string}
 */
function smwhRequest(string $route, ?string $tenant = null): array
{
    $endpoint = app(VaulterDatabase::class)->write(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint($tenant === null ? [] : ['tenant_id' => $tenant]));
    $id = $endpoint->getKey();
    $tag = ['If-Match' => webhookTag($id)];

    if ($route === 'webhook-deliveries.redeliver') {
        $delivery = app(VaulterDatabase::class)->write(static fn (): WebhookDelivery => WebhookTestHelpers::delivery($endpoint, ['status' => 'dead', 'last_error' => 'connection refused']));
        $path = 'webhook-endpoints/%s/deliveries/'.$delivery->getKey().'/redeliver';

        return ['method' => 'POST', 'path' => sprintf($path, $id), 'ghost' => sprintf($path, '987654'), 'body' => [], 'headers' => [], 'endpoint' => $id];
    }

    [$method, $path, $body, $headers] = match ($route) {
        'webhook-endpoints.store' => ['POST', 'webhook-endpoints', ['url' => 'https://matrix.test/hook', 'events' => ['folder.created']], []],
        'webhook-endpoints.update' => ['PATCH', 'webhook-endpoints/%s', ['url' => 'https://changed.test/hook'], $tag],
        'webhook-endpoints.deactivate' => ['POST', 'webhook-endpoints/%s/deactivate', [], $tag],
        'webhook-endpoints.rotate-secret' => ['POST', 'webhook-endpoints/%s/rotate-secret', [], $tag],
        'webhook-endpoints.destroy' => ['DELETE', 'webhook-endpoints/%s', [], $tag],
    };

    return ['method' => $method, 'path' => sprintf($path, $id), 'ghost' => sprintf($path, '987654'), 'body' => $body, 'headers' => $headers, 'endpoint' => $id];
}

function smwhSend(array $request, array $extra = [], ?string $path = null): TestResponse
{
    return test()->json($request['method'], V1::url($path ?? $request['path']), $request['body'], [...$request['headers'], ...$extra]);
}

/** @return array<string, mixed> */
function smwhProblem(TestResponse $response): array
{
    $body = $response->json();
    unset($body['request_id']);

    return $body;
}

/** The row's own check against the route's request. */
function smwhCheck(string $row, string $route): void
{
    config()->set('vaulter-webhooks.manage_ability', null);
    Bus::fake([DeliverWebhook::class]);

    match ($row) {
        'R1' => (function () use ($route): void {
            $request = smwhRequest($route);
            $before = WebhookEndpoint::query()->count();

            // The suite boots with the host's stack emptied: the route gets the guard a host lists in vaulter.routes.middleware.authenticated.
            app('router')->getRoutes()->getByName('vaulter.v1.'.$route)?->middleware('auth');

            smwhSend($request)->assertUnauthorized();

            expect(WebhookEndpoint::query()->count())->toBe($before);
        })(),
        'R2' => (function () use ($route): void {
            config()->set('vaulter.tenancy.enabled', true);
            config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
            $resolver = new MutableTenantResolver;
            $resolver->tenant = 'A';
            app()->instance(TenantResolver::class, $resolver);
            $request = smwhRequest($route, 'A');
            $drive = AccessTestHelpers::storage();
            $resolver->tenant = 'B';
            test()->actingAs(V1::user(1));

            if ($route === 'webhook-endpoints.store') {
                // A new endpoint has no id to hide: what it can name from another tenant is its drive filter.
                $named = test()->postJson(V1::url('webhook-endpoints'), [...$request['body'], 'drive_id' => (string) $drive->getKey()]);
                $ghost = test()->postJson(V1::url('webhook-endpoints'), [...$request['body'], 'drive_id' => 'no-such-drive']);
                $named->assertNotFound();
                expect(smwhProblem($named))->toBe(smwhProblem($ghost));

                return;
            }

            $before = WebhookEndpoint::query()->whereKey($request['endpoint'])->first()?->getAttributes();
            $foreign = smwhSend($request, ['If-Match' => webhookTag($request['endpoint'])])->assertNotFound();
            $unknown = smwhSend($request, ['If-Match' => '"webhook-endpoint.987654.0"'], $request['ghost'])->assertNotFound();

            expect(smwhProblem($foreign))->toBe(smwhProblem($unknown))
                ->and($foreign->json('code'))->toBe('webhook_endpoint_not_found')
                ->and(WebhookEndpoint::query()->whereKey($request['endpoint'])->first()?->getAttributes())->toEqual($before);
        })(),
        'R4' => (function () use ($route): void {
            $request = smwhRequest($route);
            config()->set('vaulter-webhooks.manage_ability', 'vaulter.webhooks.manage');
            Gate::define('vaulter.webhooks.manage', static fn (): bool => false);
            test()->actingAs(V1::user(1));
            $endpoints = WebhookEndpoint::query()->count();
            $before = WebhookEndpoint::query()->whereKey($request['endpoint'])->first()?->getAttributes();

            // 403 before any precondition, and the same 403 for an endpoint that does not exist: existence is not revealed.
            $known = smwhSend($request)->assertForbidden()->assertJsonPath('code', 'permission_denied');
            smwhSend($request, ['If-Match' => '"stale"'])->assertForbidden();
            $unknown = smwhSend($request, path: $request['ghost'])->assertForbidden();

            expect(smwhProblem($known))->toBe(smwhProblem($unknown))
                ->and(WebhookEndpoint::query()->count())->toBe($endpoints)
                ->and(WebhookEndpoint::query()->whereKey($request['endpoint'])->first()?->getAttributes())->toEqual($before);
        })(),
        'R9' => (function () use ($route): void {
            $request = smwhRequest($route);
            $state = new stdClass;
            $state->allowed = true;
            config()->set('vaulter-webhooks.manage_ability', 'vaulter.webhooks.manage');
            Gate::define('vaulter.webhooks.manage', static fn (): bool => $state->allowed);
            test()->actingAs(V1::user(1));
            $key = ['Idempotency-Key' => 'matrix-'.str_replace(['.', '-'], '', $route)];

            $first = smwhSend($request, $key);
            expect($first->status())->toBeLessThan(300)->and($first->headers->has('Idempotent-Replayed'))->toBeFalse();

            // While the ability holds, the same request, key and headers is answered from the receipt.
            smwhSend($request, $key)->assertHeader('Idempotent-Replayed', 'true');
            // The next request is a new one: what is scoped to a request (the replay flag) starts afresh, as in a real worker.
            app()->forgetScopedInstances();

            // The ability is revoked: the same request, key and headers is refused now, and the stored answer — a signing
            // secret among them — is not returned.
            $state->allowed = false;
            $replay = smwhSend($request, $key)->assertForbidden()->assertJsonPath('code', 'permission_denied');

            expect($replay->headers->has('Idempotent-Replayed'))->toBeFalse()
                ->and($replay->getContent())->not->toContain('whsec_');
        })(),
    };
}

it('holds the security matrix', function (string $route, string $row): void {
    smwhCheck($row, $route);
})->with('webhook matrix');

it('covers every mutating route of the package, with a reason for each row that does not apply', function (): void {
    $catalog = [];
    $receipts = [];
    foreach (app(OperationCatalog::class)->all() as $entry) {
        if ($entry['module'] !== 'webhooks') {
            continue;
        }
        foreach ($entry['policy']->routes as $route) {
            $catalog[] = $route;
            if ($entry['policy']->idempotency === IdempotencyPolicy::Receipt) {
                $receipts[] = $route;
            }
        }
    }
    $registered = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (str_starts_with($name, 'vaulter.v1.') && str_starts_with($route->getActionName(), 'Vaulter\\Webhooks\\') && array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []) {
            $registered[] = substr($name, strlen('vaulter.v1.'));
        }
    }
    $matrix = smwhMatrix();
    sort($catalog);
    sort($registered);
    $routes = array_keys($matrix);
    sort($routes);

    expect(array_values(array_diff($catalog, $routes)))->toBe([], 'routes of the catalog missing from the matrix')
        ->and(array_values(array_diff($routes, $catalog)))->toBe([], 'routes of the matrix missing from the catalog')
        ->and($registered)->toBe($catalog);
    foreach ($matrix as $route => $rows) {
        expect(array_keys($rows))->toBe(['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9']);
        foreach ($rows as $row => $reason) {
            expect($reason === null || strlen($reason) >= 12)->toBeTrue($route.' '.$row.' is n/a without a reason');
        }
        // R1, R2 and R4 apply to every route; R9 to every route that stores a receipt; R5 only to share routes, R7/R8 only to moves.
        foreach (['R1', 'R2', 'R4'] as $row) {
            expect($rows[$row])->toBeNull($route.' '.$row.' applies to every route');
        }
        expect($rows['R9'] === null)->toBe(in_array($route, $receipts, true), $route.' R9 is for the receipt routes');
    }
});
