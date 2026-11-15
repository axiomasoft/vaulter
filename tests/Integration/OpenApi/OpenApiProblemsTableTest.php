<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Vaulter\Http\Problems\ErrorsReference;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Repo\Tests\Support\ReleaseOpenApi;
use Vaulter\Testing\Support\OpenApi\OpenApiContract;

require_once dirname(__DIR__, 2).'/Repo/Arch/Support/ReleaseOpenApi.php';

require_once dirname(__DIR__).'/Http/Problems/ProblemCatalogHelpers.php';

/**
 * The Problem schema of openapi/vaulter-v1.yaml carries the machine table of the codes
 * (`x-vaulter-problems`: status, retryable, receipt, module) and the sorted list of codes as the examples of `code`. The
 * source of truth is the catalogue of the full installation; the document is regenerated, never edited by hand:
 * `UPDATE_OPENAPI_PROBLEMS=1 scripts/t sqlite -p integration tests/Integration/OpenApi/OpenApiProblemsTableTest.php`
 * rewrites the two blocks from `vaulter:errors:reference --openapi`.
 */
function openApiProblemsReference(): ErrorsReference
{
    return new ErrorsReference(new ProblemCatalog(
        static fn (): array => array_map(
            static fn (array $classes): array => array_map(static fn (string $class) => new $class, $classes),
            array_filter(monorepoProblemSources(), static fn (string $module): bool => ReleaseOpenApi::moduleChannel($module) === 'public', ARRAY_FILTER_USE_KEY),
        ),
        app('translator'),
    ));
}

/** @return array{'x-vaulter-problems': array<string, array<string, mixed>>, examples: list<string>} */
function openApiProblemsFragment(): array
{
    /** @var array{'x-vaulter-problems': array<string, array<string, mixed>>, examples: list<string>} */
    return Yaml::parse(openApiProblemsReference()->openApi());
}

/** @return array{table: array<string, array<string, mixed>>, examples: list<string>} the blocks of the document */
function openApiProblemsDocument(): array
{
    $problem = OpenApiContract::shared()->document->components->schemas->Problem;

    return [
        'table' => json_decode((string) json_encode($problem->{'x-vaulter-problems'} ?? new stdClass), true, flags: JSON_THROW_ON_ERROR),
        'examples' => $problem->properties->code->examples ?? [],
    ];
}

/** Rewrites the table and the examples of the Problem schema in the YAML text, leaving everything else byte for byte. */
function rewriteOpenApiProblems(string $yaml, string $fragment): string
{
    $fragment = Yaml::parse($fragment);
    $table = ['      x-vaulter-problems:'];
    foreach ($fragment['x-vaulter-problems'] as $code => $row) {
        $table[] = sprintf('        %s: {status: %d, retryable: %s, receipt: %s, module: %s}', $code, $row['status'], $row['retryable'], $row['receipt'], $row['module']);
    }
    $examples = array_map(static fn (string $code): string => '          - '.$code, $fragment['examples']);

    $start = strpos($yaml, "\n    Problem:\n");
    $end = strpos($yaml, "\n    PageMeta:\n");
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $schema = substr($yaml, (int) $start, (int) $end - (int) $start);

    // The old table goes (its rows are indented deeper than its key), the new one sits before additionalProperties.
    $schema = (string) preg_replace('/\n      x-vaulter-problems:(\n {8}[^\n]*)*/', '', $schema);
    $schema = (string) preg_replace('/(\n        code:\n(?:          [^\n]*\n)*?          examples:\n)(?:          - [^\n]*\n)+/', '$1'.implode("\n", $examples)."\n", $schema, 1, $count);
    expect($count)->toBe(1);
    $schema = str_replace("\n      additionalProperties: true", "\n".implode("\n", $table)."\n      additionalProperties: true", $schema);

    return substr($yaml, 0, (int) $start).$schema.substr($yaml, (int) $end);
}

if (getenv('UPDATE_OPENAPI_PROBLEMS') === '1') {
    it('rewrites the problem table of the OpenAPI document', function (): void {
        $path = OpenApiContract::specPath();
        file_put_contents($path, rewriteOpenApiProblems((string) file_get_contents($path), openApiProblemsReference()->openApi()));

        expect(true)->toBeTrue();
    });
}

it('declares the table of the full installation in x-vaulter-problems', function (): void {
    $expected = openApiProblemsFragment()['x-vaulter-problems'];
    $document = openApiProblemsDocument()['table'];

    expect($document)->not->toBe([])
        ->and($document)->toEqual($expected);
});

it('lists the sorted codes as the examples of the code property', function (): void {
    $codes = array_keys(array_filter(fullProblemCatalog()->all(), static fn (array $row): bool => ReleaseOpenApi::moduleChannel($row['module']) === 'public'));
    $sorted = $codes;
    sort($sorted, SORT_STRING);

    expect(openApiProblemsDocument()['examples'])->toBe($sorted)
        ->and(openApiProblemsFragment()['examples'])->toBe($sorted);
});

it('keeps a table row per catalogue code with the fields a client reads', function (): void {
    $catalogue = fullProblemCatalog()->all();

    foreach (openApiProblemsDocument()['table'] as $code => $row) {
        expect($row)->toHaveKeys(['status', 'retryable', 'receipt', 'module'], $code)
            ->and($row['status'])->toBe($catalogue[$code]['status'], $code)
            ->and($row['retryable'])->toBe($catalogue[$code]['retryable']->value, $code)
            ->and($row['receipt'])->toBe($catalogue[$code]['receipt']->value, $code)
            ->and($row['module'])->toBe($catalogue[$code]['module'], $code);
    }
});

it('rewrites only the two blocks of the Problem schema', function (): void {
    $yaml = (string) file_get_contents(OpenApiContract::specPath());
    $rewritten = rewriteOpenApiProblems($yaml, openApiProblemsReference()->openApi());

    // Idempotent on a current document: the generator and the committed text agree byte for byte.
    expect($rewritten)->toBe($yaml);
});
