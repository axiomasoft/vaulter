<?php

declare(strict_types=1);

use Vaulter\Operations\OperationsReference;
use Vaulter\Repo\Tests\Support\ReleaseOpenApi;
use Vaulter\Testing\Support\OpenApi\OpenApiContract;

require_once dirname(__DIR__, 2).'/Repo/Arch/Support/ReleaseOpenApi.php';

/**
 * The REST operations of openapi/vaulter-v1.yaml agree with the operation catalogue of every offered module.
 * A `receipt` route declares the Idempotency-Key parameter and the Idempotent-Replayed header; an `if_match` route
 * declares a required If-Match and the 412 and 428 answers; no other route declares either. Routes are matched by
 * operationId, which is the route name without the version prefix (OpenApiRouteCoverageTest).
 */

/**
 * @return array<string, list<array{operation: string, concurrency: string, idempotency: string}>> catalogue rows by route
 */
function policiesByRoute(): array
{
    $byRoute = [];
    foreach (app(OperationsReference::class)->rows() as $row) {
        if (ReleaseOpenApi::moduleChannel($row['module']) !== 'public') {
            continue;
        }
        foreach ($row['routes'] as $route) {
            $byRoute[$route][] = ['operation' => $row['operation'], 'concurrency' => $row['concurrency'], 'idempotency' => $row['idempotency']];
        }
    }
    ksort($byRoute);

    return $byRoute;
}

/**
 * The lower-case names of the parameters of an operation, with the $ref targets resolved. An optional parameter (the
 * If-Match of an upload that may replace a file) is listed only when `$required` is false.
 *
 * @return list<string>
 */
function openApiParameters(OpenApiContract $contract, stdClass $operation, bool $required = true): array
{
    $names = [];
    foreach ($operation->parameters ?? [] as $index => $parameter) {
        [$resolved] = $contract->resolve($parameter, '#/operation/parameters/'.$index);
        if ($required === (($resolved->required ?? false) === true)) {
            $names[] = strtolower((string) $resolved->name);
        }
    }

    return $names;
}

/** @return list<string> the response statuses of an operation */
function openApiStatuses(stdClass $operation): array
{
    return array_map('strval', array_keys(get_object_vars($operation->responses)));
}

function openApiDeclaresReplayHeader(OpenApiContract $contract, stdClass $operation): bool
{
    foreach (get_object_vars($operation->responses) as $status => $response) {
        if (!str_starts_with((string) $status, '2')) {
            continue;
        }
        [$resolved] = $contract->resolve($response, '#/operation/responses/'.$status);
        if (isset($resolved->headers) && property_exists($resolved->headers, 'Idempotent-Replayed')) {
            return true;
        }
    }

    return false;
}

it('describes every REST route of the catalogue in the document', function (): void {
    $documented = array_keys(OpenApiContract::shared()->operations());
    $missing = [];

    // The Tus and part-upload routes are machine protocols with their own dedup; they may be absent by design.
    foreach (policiesByRoute() as $route => $rows) {
        if (array_unique(array_column($rows, 'idempotency')) !== ['protocol'] && !in_array($route, $documented, true)) {
            $missing[] = $route;
        }
    }

    expect($missing)->toBe([]);
});

it('declares Idempotency-Key and Idempotent-Replayed on every receipt route and nowhere else', function (): void {
    $contract = OpenApiContract::shared();
    $receipt = [];
    foreach (policiesByRoute() as $route => $rows) {
        if (in_array('receipt', array_column($rows, 'idempotency'), true)) {
            $receipt[] = $route;
        }
    }
    $violations = [];

    foreach ($contract->operations() as $id => $entry) {
        $declares = in_array('idempotency-key', [...openApiParameters($contract, $entry['operation']), ...openApiParameters($contract, $entry['operation'], false)], true);
        $expected = in_array($id, $receipt, true);

        if ($declares !== $expected) {
            $violations[] = $id.($expected ? ': receipt route without Idempotency-Key' : ': Idempotency-Key on a route without a receipt policy');
        }
        if ($expected && !openApiDeclaresReplayHeader($contract, $entry['operation'])) {
            $violations[] = $id.': receipt route without Idempotent-Replayed on a 2xx answer';
        }
    }
    foreach (array_diff($receipt, array_keys($contract->operations())) as $route) {
        $violations[] = $route.': receipt route missing from the document';
    }

    expect($violations)->toBe([]);
});

it('declares a required If-Match with 412 and 428 on every if_match route and nowhere else', function (): void {
    $contract = OpenApiContract::shared();
    $ifMatch = [];
    foreach (policiesByRoute() as $route => $rows) {
        if (in_array('if_match', array_column($rows, 'concurrency'), true)) {
            $ifMatch[] = $route;
        }
    }
    $violations = [];

    foreach ($contract->operations() as $id => $entry) {
        $declares = in_array('if-match', openApiParameters($contract, $entry['operation']), true);
        $expected = in_array($id, $ifMatch, true);

        if ($declares !== $expected) {
            $violations[] = $id.($expected ? ': if_match route without If-Match' : ': If-Match on a route without an if_match policy');
        }
        if ($expected) {
            $statuses = openApiStatuses($entry['operation']);
            foreach (array_diff(['412', '428'], $statuses) as $missing) {
                $violations[] = $id.': if_match route without the '.$missing.' answer';
            }
        }
    }
    foreach (array_diff($ifMatch, array_keys($contract->operations())) as $route) {
        $violations[] = $route.': if_match route missing from the document';
    }

    expect($violations)->toBe([]);
});
