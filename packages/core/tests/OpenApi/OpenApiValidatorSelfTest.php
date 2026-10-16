<?php

declare(strict_types=1);

use Vaulter\Testing\Support\OpenApi\OpenApiContract;
use Vaulter\Testing\Support\OpenApi\OpenApiResponseValidator;
use Vaulter\Testing\Support\OpenApi\UnsupportedOpenApiConstruct;

/**
 * The validator is itself under test: each fixture is a response that breaks the contract in
 * one way, built from a real example plus a mutation, and the validator must name the breach.
 * A green contract suite means nothing if the validator cannot fail.
 */
function negativeFixtures(): array
{
    $fixtures = [];
    foreach (glob(__DIR__.'/../Fixtures/openapi/negative/*.json') ?: [] as $path) {
        $fixtures[basename($path, '.json')] = [json_decode((string) file_get_contents($path), true)];
    }

    return $fixtures;
}

/**
 * @param  array<string, mixed>                                          $fixture
 * @return array{0: string, 1: int, 2: array<string, string>, 3: string}
 */
function fixtureResponse(array $fixture): array
{
    // Objects, not arrays: an empty `{}` must stay an object through the mutation.
    $example = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/openapi/examples/'.$fixture['example'].'.json'), false);

    $body = $example->body;
    foreach ($fixture['set'] ?? [] as $path => $value) {
        $segments = explode('.', $path);
        $leaf = array_pop($segments);
        $target = $body;
        foreach ($segments as $segment) {
            $target = $target->{$segment};
        }
        $target->{$leaf} = $value;
    }
    foreach ($fixture['unset'] ?? [] as $path) {
        $segments = explode('.', $path);
        $leaf = array_pop($segments);
        $target = $body;
        foreach ($segments as $segment) {
            $target = $target->{$segment};
        }
        unset($target->{$leaf});
    }

    $headers = ($fixture['headers'] ?? []) + (array) $example->headers;
    foreach ($fixture['unset_headers'] ?? [] as $header) {
        unset($headers[$header]);
    }

    return [
        $fixture['operation_id'] ?? $example->operation_id,
        $fixture['status'] ?? $example->status,
        $headers,
        json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
    ];
}

it('names the breach of every negative fixture', function (array $fixture): void {
    [$operation, $status, $headers, $body] = fixtureResponse($fixture);

    $violations = (new OpenApiResponseValidator(OpenApiContract::shared()))->validate($operation, $status, $headers, $body);

    expect($violations)->not->toBeEmpty($fixture['description'])
        ->and(implode("\n", $violations))->toContain($fixture['expect']);
})->with(negativeFixtures());

it('covers the eight breaches the contract suite promises to catch', function (): void {
    expect(array_keys(negativeFixtures()))->toContain(
        '01-undocumented-status',
        '02-node-id-number',
        '03-presentation-empty-array',
        '04-missing-required-etag',
        '05-nullable-gets-false',
        '06-problem-without-code',
        '07-not-modified-with-body',
        '08-error-wrong-content-type',
    );
});

it('passes an untouched example, so the fixtures fail for their mutation only', function (): void {
    foreach (['node', 'problem-412', 'problem-428', 'drive'] as $name) {
        [$operation, $status, $headers, $body] = fixtureResponse(['example' => $name]);

        expect((new OpenApiResponseValidator(OpenApiContract::shared()))->validate($operation, $status, $headers, $body))->toBe([], $name);
    }
});

it('refuses an operation the document does not describe', function (): void {
    expect((new OpenApiResponseValidator(OpenApiContract::shared()))->validate('nodes.nope', 200, [], ''))
        ->toBe(["operation 'nodes.nope' is not described in the OpenAPI document"]);
});

it('stops on constructs it cannot judge instead of skipping them', function (string $yaml, string $construct): void {
    expect(fn () => OpenApiContract::fromYaml($yaml))->toThrow(UnsupportedOpenApiConstruct::class, $construct);
})->with([
    'discriminator' => ["openapi: 3.1.1\npaths: {}\ncomponents:\n  schemas:\n    Pet:\n      oneOf: []\n      discriminator:\n        propertyName: kind\n", 'discriminator'],
    'callbacks' => ["openapi: 3.1.1\npaths:\n  /x:\n    post:\n      operationId: x\n      callbacks:\n        done: {}\n      responses: {}\n", 'callbacks'],
    'external ref' => ["openapi: 3.1.1\npaths: {}\ncomponents:\n  schemas:\n    Pet:\n      \$ref: 'other.yaml#/Pet'\n", 'other.yaml#/Pet'],
    'dynamic ref' => ["openapi: 3.1.1\npaths: {}\ncomponents:\n  schemas:\n    Pet:\n      \$dynamicRef: '#meta'\n", '$dynamicRef'],
]);

it('lets a schema property be called like a keyword', function (): void {
    $contract = OpenApiContract::fromYaml("openapi: 3.1.1\npaths: {}\ncomponents:\n  schemas:\n    Pet:\n      type: object\n      properties:\n        callbacks:\n          type: integer\n");

    expect($contract->operations())->toBe([]);
});

it('matches a media type exactly, by type wildcard, then by the full wildcard', function (): void {
    $contract = OpenApiContract::fromYaml(<<<'YAML'
    openapi: 3.1.1
    paths:
      /x:
        get:
          operationId: x
          responses:
            '200':
              description: ok
              content:
                text/*: {}
                '*/*': {}
    YAML);
    $validator = new OpenApiResponseValidator($contract);

    expect($validator->validate('x', 200, ['content-type' => 'text/plain; charset=utf-8'], 'hi'))->toBe([])
        ->and($validator->validate('x', 200, ['content-type' => 'image/png'], 'png'))->toBe([])
        ->and($validator->validate('x', 200, [], 'no type'))->not->toBe([]);
});
