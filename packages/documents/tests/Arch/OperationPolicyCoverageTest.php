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
 * I11 / I12 coverage of the documents' operation policies (D47, knowledge p6-design#catalog), for every route of the
 * package: (a) every operation name in src is in the catalog, (b) every mutating v1 route of the package is in exactly
 * one policy, (c) every route of a receipt policy reads the Idempotency-Key and no other route does, (d) every route of
 * an if_match policy is in the 428 table of IfMatchRequiredTest. The export is safe (a GET) and not in the catalog.
 */

/** @return array<string, OperationPolicy> */
function opcPolicies(): array
{
    $policies = [];
    foreach (app(OperationCatalog::class)->all() as $name => $entry) {
        if ($entry['module'] === 'documents') {
            $policies[$name] = $entry['policy'];
        }
    }

    return $policies;
}

/** @return array<string, Route> the mutating v1 routes the documents package owns, by name without the prefix */
function opcRoutes(): array
{
    $routes = [];
    foreach (Router::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (!str_starts_with($name, 'vaulter.v1.') || !str_starts_with($route->getActionName(), 'Vaulter\\Documents\\')) {
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
    }
    sort($names);

    return array_values(array_unique($names));
}

it('(a) declares every operation name of the documents sources in the catalog', function (): void {
    $names = opcOperationNames();
    $catalog = app(OperationCatalog::class);

    expect($names)->toContain('documents.documents.create', 'documents.documents.update', 'documents.documents.update_content', 'documents.documents.snapshot', 'documents.documents.restore', 'documents.comments.add', 'documents.comments.resolve', 'documents.comments.delete');
    $missing = array_values(array_filter($names, static fn (string $name): bool => $catalog->policy($name) === null || $catalog->module($name) !== 'documents'));
    expect($missing)->toBe([])
        // The catalog lists no operation the sources do not run.
        ->and(array_values(array_diff(array_keys(opcPolicies()), $names)))->toBe([]);
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

it('(c) reads the Idempotency-Key on every route of a receipt policy and on no other', function (): void {
    $routes = opcRoutes();
    $missing = [];
    foreach (opcPolicies() as $name => $policy) {
        foreach ($policy->routes as $route) {
            $reads = in_array(ReadIdempotencyKey::ALIAS, $routes[$route]->gatherMiddleware(), true);
            if ($policy->idempotency === IdempotencyPolicy::Receipt && !$reads) {
                $missing[] = $name.' → '.$route;
            }
            // A route that does not read the key belongs to a natural, conditional or protocol policy.
            if ($policy->idempotency !== IdempotencyPolicy::Receipt && $reads) {
                $missing[] = $name.' reads a key although it is '.$policy->idempotency->value.' → '.$route;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('declares the content PUT conditional and the comment writes receipts with their own version', function (): void {
    $policies = opcPolicies();

    expect($policies['documents.documents.update_content']->idempotency)->toBe(IdempotencyPolicy::Conditional)
        ->and($policies['documents.documents.update_content']->concurrency)->toBe(ConcurrencyPolicy::IfMatch)
        ->and($policies['documents.comments.resolve']->idempotency)->toBe(IdempotencyPolicy::Receipt)
        ->and($policies['documents.comments.resolve']->concurrency)->toBe(ConcurrencyPolicy::IfMatch)
        ->and($policies['documents.comments.delete']->idempotency)->toBe(IdempotencyPolicy::Receipt)
        ->and($policies['documents.comments.delete']->concurrency)->toBe(ConcurrencyPolicy::IfMatch);
});

it('(d) lists every if_match route of the package in the 428 table of IfMatchRequiredTest', function (): void {
    $routes = [];
    foreach (opcPolicies() as $policy) {
        if ($policy->concurrency === ConcurrencyPolicy::IfMatch) {
            array_push($routes, ...$policy->routes);
        }
    }
    sort($routes);

    // The table of requests lives with the test that sends them (Http/IfMatchRequiredTest: 428 without If-Match, with `*`).
    $source = (string) file_get_contents(__DIR__.'/../Http/IfMatchRequiredTest.php');
    expect(preg_match("/dataset\\('im routes', \\[(.*?)\\]\\);/s", $source, $match))->toBe(1);
    preg_match_all("/'([a-z.]+)'/", $match[1], $listed);
    $listed = array_values(array_unique($listed[1]));
    sort($listed);

    expect($listed)->toBe($routes);
});
