<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Testing\Tenancy\MutableTenantResolver;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;

/**
 * The read security matrix of the webhooks' GET/HEAD routes (P5.2, knowledge p5-security#read-matrix): every read route ×
 * the rows R1–R3 and R10 of the audit. The admin surface is global: its one gate is the `manage` ability (R4 of the
 * mutation matrix), which answers 403 before any lookup, so a per-resource View (R3) does not exist; R11 and R12 are
 * downloads and collaboration sessions, which this package has none of.
 *
 * The coverage test holds the route set to `Route::getRoutes()` (GET/HEAD) and to the OpenAPI operations.
 */
afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

/**
 * @return array<string, array{path: string, rows: array<string, string|null>}>
 */
function rsmwhMatrix(): array
{
    $noView = 'the admin surface is global: no per-resource View exists, its only gate is the manage ability, which answers 403 before any lookup — an unknown id included (owner: SecurityMatrixTest R4, WebhookEndpointsV1Test)';
    $noDownload = 'not a download or a session: the package serves JSON admin reads only';
    $row = static fn (array $over = []): array => [...['R1' => null, 'R2' => null, 'R3' => $noView, 'R10' => null, 'R11' => $noDownload, 'R12' => $noDownload], ...$over];
    $one = 'one resource, not a list: R2 holds its concealment';

    return [
        'webhook-endpoints.index' => ['path' => 'webhook-endpoints', 'rows' => $row(['R2' => 'a list is scoped to the caller\'s tenant: R10 holds the foreign endpoint out of items'])],
        'webhook-endpoints.show' => ['path' => 'webhook-endpoints/{endpoint}', 'rows' => $row(['R10' => $one])],
        'webhook-deliveries.index' => ['path' => 'webhook-endpoints/{endpoint}/deliveries', 'rows' => $row(['R10' => null])],
    ];
}

/** @return array<string, array{0: string, 1: string}> */
function rsmwhCells(): array
{
    $cells = [];
    foreach (rsmwhMatrix() as $route => $entry) {
        foreach ($entry['rows'] as $row => $reason) {
            if ($reason === null) {
                $cells[$route.' '.$row] = [$route, $row];
            }
        }
    }

    return $cells;
}

dataset('webhook read matrix', fn (): array => rsmwhCells());

function rsmwhProblem(TestResponse $response): array
{
    $body = $response->json() ?? [];
    unset($body['request_id']);

    return $body;
}

function rsmwhGet(string $route, string|int $endpoint): TestResponse
{
    return test()->getJson(V1::url(strtr(rsmdrvPath($route), ['{endpoint}' => (string) $endpoint])));
}

function rsmdrvPath(string $route): string
{
    return rsmwhMatrix()[$route]['path'];
}

it('holds the webhooks read security matrix', function (string $route, string $row): void {
    config()->set('vaulter-webhooks.manage_ability', null);
    $arrange = static function (?string $tenant = null): WebhookEndpoint {
        return app(VaulterDatabase::class)->write(static function () use ($tenant): WebhookEndpoint {
            $endpoint = WebhookTestHelpers::endpoint($tenant === null ? [] : ['tenant_id' => $tenant]);
            WebhookTestHelpers::delivery($endpoint, ['status' => 'dead', 'last_error' => 'connection refused']);

            return $endpoint;
        });
    };

    match ($row) {
        'R1' => (function () use ($route, $arrange): void {
            $endpoint = $arrange();
            app('router')->getRoutes()->getByName('vaulter.v1.'.$route)?->middleware('auth');

            rsmwhGet($route, $endpoint->getKey())->assertUnauthorized();
        })(),
        'R2' => (function () use ($route, $arrange): void {
            config()->set('vaulter.tenancy.enabled', true);
            config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
            $resolver = new MutableTenantResolver;
            $resolver->tenant = 'A';
            app()->instance(TenantResolver::class, $resolver);
            $endpoint = $arrange('A');
            $resolver->tenant = 'B';
            test()->actingAs(V1::user(1));

            $foreign = rsmwhGet($route, $endpoint->getKey())->assertNotFound();
            $unknown = rsmwhGet($route, '987654')->assertNotFound();

            expect(rsmwhProblem($foreign))->toBe(rsmwhProblem($unknown))
                ->and($foreign->json('code'))->toBe('webhook_endpoint_not_found');
        })(),
        'R10' => (function () use ($route, $arrange): void {
            config()->set('vaulter.tenancy.enabled', true);
            config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
            $resolver = new MutableTenantResolver;
            $resolver->tenant = 'A';
            app()->instance(TenantResolver::class, $resolver);
            $foreign = $arrange('A');
            $resolver->tenant = 'B';
            $own = $arrange('B');
            test()->actingAs(V1::user(1));

            if ($route === 'webhook-endpoints.index') {
                $ids = collect(rsmwhGet($route, $own->getKey())->assertOk()->json('data'))->pluck('id')->map(strval(...))->all();

                expect($ids)->toContain((string) $own->getKey())->not->toContain((string) $foreign->getKey());

                return;
            }

            // The deliveries of an endpoint of another tenant are the concealed 404 of a missing one, with no row of it.
            $hidden = rsmwhGet($route, $foreign->getKey());
            $ghost = rsmwhGet($route, '987654');

            expect($hidden->status())->toBe(404)
                ->and(rsmwhProblem($hidden))->toBe(rsmwhProblem($ghost))
                ->and(rsmwhGet($route, $own->getKey())->assertOk()->json('data'))->toHaveCount(1)
                ->and(WebhookDelivery::query()->count())->toBe(2);
        })(),
    };
})->with('webhook read matrix');

it('answers 403 for every read route when the manage ability is denied, an unknown id included', function (): void {
    config()->set('vaulter-webhooks.manage_ability', 'vaulter.webhooks.manage');
    Gate::define('vaulter.webhooks.manage', static fn (): bool => false);
    $endpoint = app(VaulterDatabase::class)->write(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint());
    test()->actingAs(V1::user(1));

    foreach (array_keys(rsmwhMatrix()) as $route) {
        $known = rsmwhGet($route, $endpoint->getKey())->assertForbidden();
        $unknown = rsmwhGet($route, '987654')->assertForbidden();

        expect(rsmwhProblem($known))->toBe(rsmwhProblem($unknown), $route);
    }
});

it('covers every GET/HEAD route of the package, with a reason for each row that does not apply', function (): void {
    $registered = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (str_starts_with($name, 'vaulter.v1.') && str_starts_with($route->getActionName(), 'Vaulter\\Webhooks\\') && array_intersect($route->methods(), ['GET', 'HEAD']) !== []) {
            $registered[] = substr($name, strlen('vaulter.v1.'));
        }
    }
    $spec = Yaml::parseFile(dirname(__DIR__, 4).'/openapi/vaulter-v1.yaml');
    $operations = [];
    foreach ($spec['paths'] as $item) {
        foreach (['get', 'head'] as $method) {
            if (isset($item[$method]['operationId'])) {
                $operations[] = (string) $item[$method]['operationId'];
            }
        }
    }
    $matrix = rsmwhMatrix();
    $routes = array_keys($matrix);
    sort($registered);
    sort($routes);

    expect($registered)->toBe($routes)
        ->and(array_values(array_diff($routes, $operations)))->toBe([], 'routes of the read matrix without an OpenAPI operation');
    foreach ($matrix as $route => $entry) {
        expect(array_keys($entry['rows']))->toBe(['R1', 'R2', 'R3', 'R10', 'R11', 'R12']);
        foreach ($entry['rows'] as $row => $reason) {
            expect($reason === null || strlen($reason) >= 12)->toBeTrue($route.' '.$row.' is n/a without a reason');
        }
    }
});
