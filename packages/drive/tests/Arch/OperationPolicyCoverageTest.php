<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\Finder\Finder;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationCatalog;
use Vaulter\Operations\OperationPolicy;

/**
 * I11 / I12 coverage of the drive's operation policies (D47, knowledge p6-design#catalog), for the routes P6.2 owns:
 * (a) every operation name in src is in the catalog, (b) every mutating v1 route of the package is in exactly one
 * policy, (c) every route of a receipt policy reads the Idempotency-Key, (d) every route of an if_match policy is in the 428
 * table of IfMatchRequiredTest. The routes of P6.3 (access, shares, drive lifecycle) and the direct file bodies are listed
 * below with the reason; a list entry that is covered by now fails the test, so the lists only shrink.
 */
/** Routes of receipt policies that P6.3 brings under the key (access, shares, drive lifecycle). */
const OPC_PENDING_P63 = [
    'grants.store', 'grants.destroy', 'access.inheritance.restrict', 'access.inheritance.restore',
    'shares.store', 'shares.update', 'shares.rotate', 'shares.destroy',
    'drives.archive', 'drives.unarchive', 'drives.destroy',
];

/** Routes of receipt policies whose body is the file bytes: a transport fingerprint does not cover multipart content (follow-up). */
const OPC_PENDING_DIRECT_BODY = ['files.store', 'files.revisions.store'];

/** @return array<string, OperationPolicy> */
function opcPolicies(): array
{
    $policies = [];
    foreach (app(OperationCatalog::class)->all() as $name => $entry) {
        if ($entry['module'] === 'drive') {
            $policies[$name] = $entry['policy'];
        }
    }

    return $policies;
}

/** @return array<string, Route> the mutating v1 routes the drive package owns, by name without the prefix */
function opcRoutes(): array
{
    $routes = [];
    foreach (Router::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (!str_starts_with($name, 'vaulter.v1.') || !str_starts_with($route->getActionName(), 'Vaulter\\Drive\\')) {
            continue;
        }
        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []) {
            $routes[substr($name, strlen('vaulter.v1.'))] = $route;
        }
    }

    return $routes;
}

/** @return list<string> */
function opcOperationNames(): array
{
    $names = [];
    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src')->name('*.php') as $file) {
        $source = $file->getContents();
        if (preg_match_all("/new OperationSpec\\('([a-z_.]+)'(?!\\s*\\.)/", $source, $literal) > 0) {
            array_push($names, ...$literal[1]);
        }
        if (preg_match_all("/new OperationSpec\\('([a-z_.]+\\.)'\\s*\\.\\s*\\\$verb/", $source, $prefixes) > 0) {
            preg_match_all("/(?:write|ingestSource)\\('([a-z_]+)'/", $source, $verbs);
            foreach ($prefixes[1] as $prefix) {
                foreach (array_unique($verbs[1]) as $verb) {
                    $names[] = $prefix.$verb;
                }
            }
        }
        // The trash is emptied by a keyed operation of its own besides the per-root purge.
        if (str_contains($source, "new OperationSpec('drive.trash.empty'")) {
            $names[] = 'drive.trash.empty';
        }
    }
    sort($names);

    return array_values(array_unique($names));
}

it('(a) declares every operation name of the drive sources in the catalog', function (): void {
    $names = opcOperationNames();
    $catalog = app(OperationCatalog::class);

    expect($names)->toContain('drive.nodes.move', 'drive.trash.purge', 'drive.trash.empty', 'drive.files.delete_revision', 'drive.uploads.initiate');
    $missing = array_values(array_filter($names, static fn (string $name): bool => $catalog->policy($name) === null || $catalog->module($name) !== 'drive'));
    expect($missing)->toBe([]);
});

it('(b) puts every mutating v1 route of the package in exactly one policy', function (): void {
    $owners = [];
    foreach (opcPolicies() as $name => $policy) {
        foreach ($policy->routes as $route) {
            $owners[$route][] = $name;
        }
    }
    $routes = opcRoutes();

    expect(array_values(array_diff(array_keys($routes), array_keys($owners))))->toBe([])
        ->and(array_values(array_diff(array_keys($owners), array_keys($routes))))->toBe([])
        ->and(array_values(array_filter($owners, static fn (array $names): bool => count($names) !== 1)))->toBe([]);
});

it('(c) reads the Idempotency-Key on every route of a receipt policy', function (): void {
    $routes = opcRoutes();
    $pending = [...OPC_PENDING_P63, ...OPC_PENDING_DIRECT_BODY];
    $missing = [];
    $covered = [];
    foreach (opcPolicies() as $name => $policy) {
        foreach ($policy->routes as $route) {
            $reads = in_array(ReadIdempotencyKey::ALIAS, $routes[$route]->gatherMiddleware(), true);
            if ($policy->idempotency === IdempotencyPolicy::Receipt && !$reads && !in_array($route, $pending, true)) {
                $missing[] = $name.' → '.$route;
            }
            // A route that does not read the key belongs to a natural, conditional or protocol policy, or is pending.
            if ($policy->idempotency !== IdempotencyPolicy::Receipt && $reads) {
                $missing[] = $name.' reads a key although it is '.$policy->idempotency->value.' → '.$route;
            }
            if ($reads && in_array($route, $pending, true)) {
                $covered[] = $route;
            }
        }
    }

    expect($missing)->toBe([])->and($covered)->toBe([]);
});

it('(d) lists every if_match route of the package in the 428 table of IfMatchRequiredTest', function (): void {
    $routes = [];
    foreach (opcPolicies() as $policy) {
        if ($policy->concurrency === ConcurrencyPolicy::IfMatch) {
            array_push($routes, ...array_filter($policy->routes, static fn (string $route): bool => !in_array($route, OPC_PENDING_P63, true)));
        }
    }
    sort($routes);

    // The table of requests lives with the test that sends them (Http/IfMatchRequiredTest: 428 without If-Match, with `*`).
    $source = (string) file_get_contents(__DIR__.'/../Http/IfMatchRequiredTest.php');
    expect(preg_match("/dataset\\('im routes', \\[(.*?)\\]\\);/s", $source, $match))->toBe(1);
    preg_match_all("/'([a-z.]+)'/", $match[1], $listed);
    $listed = array_values(array_unique($listed[1]));
    sort($listed);

    // `uploads.store` is the append policy of a new session whose If-Match applies to a revision of an existing file only.
    expect(array_values(array_diff($routes, $listed)))->toBe([])
        ->and(array_values(array_diff($listed, $routes)))->toBe(['uploads.store']);
});
