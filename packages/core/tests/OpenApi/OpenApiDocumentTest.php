<?php

declare(strict_types=1);

use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Tests\Support\OpenApi\OpenApiContract;

/**
 * The document itself: it parses, every reference resolves, and the cross-cutting promises of
 * the API (preconditions, validators, headers, error shape) are declared on every operation
 * they apply to — a new operation cannot forget them.
 */

/** The operations that change a node or a document and so require If-Match. */
const IF_MATCH_OPERATIONS = [
    'documents.content.update',
    'documents.revisions.restore',
    'files.revisions.restore',
    'files.revisions.store',
    'nodes.move',
    'nodes.properties.update',
    'nodes.purge',
    'nodes.restore',
    'nodes.trash',
    'nodes.update',
];

/** The single resources that answer a matching If-None-Match with 304. */
const CONDITIONAL_GET_OPERATIONS = ['documents.content.show', 'nodes.show'];

/** @return array{0: array<string, mixed>, 1: string} */
function describedResponse(string $operationId, string $status): array
{
    $contract = OpenApiContract::shared();
    $operation = $contract->operation($operationId)['operation'];
    [$response, $pointer] = $contract->resolve($operation->responses->{$status}, '/paths');

    return [$response, $pointer];
}

function declaresHeaderParameter(string $operationId, string $header, bool $required): bool
{
    $contract = OpenApiContract::shared();
    foreach ($contract->operation($operationId)['operation']->parameters ?? [] as $parameter) {
        [$parameter] = $contract->resolve($parameter, '');
        if ($parameter->in === 'header' && strtolower($parameter->name) === strtolower($header) && ($parameter->required ?? false) === $required) {
            return true;
        }
    }

    return false;
}

it('is an OpenAPI 3.1 document of the first major version', function (): void {
    $document = OpenApiContract::shared()->document;

    expect($document->openapi)->toStartWith('3.1.')
        ->and($document->info->version)->toBe('1.0.0')
        ->and($document->servers[0]->url)->toBe('/api/vaulter/v1');
});

it('resolves every $ref', function (): void {
    $contract = OpenApiContract::shared();

    foreach ($contract->references() as [$location, $target]) {
        expect(fn () => $contract->at($contract->pointerOf($target)))->not->toThrow(Throwable::class, "{$location} -> {$target}");
    }
});

it('gives every operation a unique id, a tag, a summary and a success response', function (): void {
    foreach (OpenApiContract::shared()->operations() as $id => $entry) {
        $operation = $entry['operation'];
        $statuses = array_map('strval', array_keys(get_object_vars($operation->responses)));

        expect($operation->tags)->not->toBeEmpty("{$id}: tags")
            ->and($operation->summary)->not->toBe('', "{$id}: summary")
            ->and(array_filter($statuses, static fn (string $status): bool => $status[0] === '2' || $status === '302'))->not->toBeEmpty("{$id}: a success status");
    }
});

it('requires If-Match on exactly the operations that change a node or a document, with 412 and 428', function (): void {
    $required = [];
    foreach (array_keys(OpenApiContract::shared()->operations()) as $id) {
        if (declaresHeaderParameter($id, 'If-Match', required: true)) {
            $required[] = $id;
        }
    }
    sort($required);

    expect($required)->toBe(IF_MATCH_OPERATIONS);
    foreach ($required as $id) {
        $statuses = array_map('strval', array_keys(get_object_vars(OpenApiContract::shared()->operation($id)['operation']->responses)));
        expect($statuses)->toContain('412', '428');
    }
});

it('offers If-None-Match and a body-less 304 on every conditional GET', function (): void {
    foreach (CONDITIONAL_GET_OPERATIONS as $id) {
        [$response] = describedResponse($id, '304');

        expect(declaresHeaderParameter($id, 'If-None-Match', required: false))->toBeTrue("{$id}: If-None-Match")
            ->and(isset($response->content))->toBeFalse("{$id}: 304 has no content")
            ->and(isset($response->headers->ETag))->toBeTrue("{$id}: 304 repeats the ETag");
    }
});

it('describes streamed responses as bytes with disposition and cache headers', function (): void {
    foreach (['files.download', 'files.revisions.download', 'public-shares.download', 'documents.export'] as $id) {
        [$response] = describedResponse($id, '200');

        expect($response->content)->toHaveProperty('application/octet-stream')
            ->and($response->headers)->toHaveProperties(['Content-Disposition', 'Cache-Control', 'X-Content-Type-Options']);
    }
});

it('lets every operation answer an unexpected failure as a problem', function (): void {
    foreach (array_keys(OpenApiContract::shared()->operations()) as $id) {
        expect(property_exists(OpenApiContract::shared()->operation($id)['operation']->responses, '500'))->toBeTrue($id);
    }
});

it('stamps every response with the api version and the request id, and every error is a problem', function (): void {
    $contract = OpenApiContract::shared();

    foreach ($contract->operations() as $id => $entry) {
        foreach (get_object_vars($entry['operation']->responses) as $status => $response) {
            [$resolved] = $contract->resolve($response, '');

            foreach (['X-Vaulter-Api-Version', 'X-Request-Id', 'Cache-Control'] as $header) {
                expect(property_exists($resolved->headers ?? new stdClass, $header))->toBeTrue("{$id} {$status}: {$header}");
            }
            if ((int) $status >= 400) {
                expect(property_exists($resolved->content ?? new stdClass, 'application/problem+json'))->toBeTrue("{$id} {$status}: problem+json");
            }
        }
    }
});

it('requires the stable fields of a problem and lists every catalog code as an example', function (): void {
    $problem = OpenApiContract::shared()->document->components->schemas->Problem;
    $examples = $problem->properties->code->examples;
    $catalog = array_keys(app(ProblemCatalog::class)->all());
    sort($catalog);

    expect($problem->required)->toBe(['type', 'title', 'status', 'code', 'request_id'])
        ->and($examples)->toBe($catalog);
});
