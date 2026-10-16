<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Testing\Support\OpenApi\OpenApiContract;
use Vaulter\Testing\Support\OpenApi\OpenApiResponseValidator;

/**
 * openapi/examples/*.json: one real response per resource, written from feature requests
 * (VAULTER_OPENAPI_EXAMPLES_WRITE=1) and otherwise checked against the schema and against the
 * shape the API answers with today. The client's contract tests read the same files.
 */
const EXAMPLE_HEADERS = ['content-type', 'cache-control', 'etag', 'vary', 'referrer-policy', 'x-vaulter-api-version', 'x-request-id'];

function examplesDirectory(): string
{
    return dirname(__DIR__, 4).'/openapi/examples';
}

/**
 * name => [operation id, status, producer of the live response]
 *
 * @return array<string, array{0: string, 1: int, 2: Closure(): TestResponse}>
 */
function exampleProducers(): array
{
    $test = test();
    $prepare = static function () use ($test): array {
        $test->actingAs(V1::user());
        $drive = V1::drive();
        $folder = V1::folder($drive, 'Docs');
        $file = V1::file($drive, 'report.txt', parent: $folder->id);

        return [$drive, $folder, $file];
    };

    return [
        'node' => ['nodes.show', 200, function () use ($test, $prepare): TestResponse {
            [, , $file] = $prepare();

            return $test->getJson(V1::url('nodes/'.$file->id))->assertOk();
        }],
        'drive' => ['drives.show', 200, function () use ($test, $prepare): TestResponse {
            [$drive] = $prepare();

            return $test->getJson(V1::url('drives/'.$drive->snapshot()->id))->assertOk();
        }],
        'file-revisions' => ['files.revisions.index', 200, function () use ($test, $prepare): TestResponse {
            [, , $file] = $prepare();

            return $test->getJson(V1::url('files/'.$file->id.'/revisions'))->assertOk();
        }],
        'document-content' => ['documents.content.show', 200, function () use ($test, $prepare): TestResponse {
            [$drive] = $prepare();
            $document = Docs::create($drive->snapshot()->id, ['type' => 'vaulter/rich-text', 'content' => Docs::doc('Hello')]);

            return $test->getJson(V1::url('documents/'.$document.'/content'))->assertOk();
        }],
        'grant' => ['grants.store', 201, function () use ($test, $prepare): TestResponse {
            [, $folder] = $prepare();

            return $test->postJson(V1::url('nodes/'.$folder->id.'/grants'), ['subject' => ['type' => V1::user(7)->getMorphClass(), 'id' => 7], 'role' => 'reader'])->assertCreated();
        }],
        'share' => ['shares.store', 201, function () use ($test, $prepare): TestResponse {
            config()->set('vaulter.features.share_links', true);
            [, , $file] = $prepare();

            return $test->postJson(V1::url('nodes/'.$file->id.'/shares'), ['role' => 'reader', 'audience' => 'authenticated', 'max_uses' => 3])->assertCreated();
        }],
        'change-page' => ['changes.index', 200, function () use ($test, $prepare): TestResponse {
            [$drive] = $prepare();
            $start = $test->getJson(V1::url('drives/'.$drive->snapshot()->id.'/changes/start'))->assertOk()->json('data.cursor');
            V1::folder($drive, 'After');

            return $test->getJson(V1::url('drives/'.$drive->snapshot()->id.'/changes?cursor='.urlencode($start)))->assertOk();
        }],
        'problem-412' => ['nodes.update', 412, function () use ($test, $prepare): TestResponse {
            [, , $file] = $prepare();

            return $test->patchJson(V1::url('nodes/'.$file->id), ['name' => 'renamed.txt'], ['If-Match' => '"stale"'])->assertStatus(412);
        }],
        'problem-428' => ['nodes.update', 428, function () use ($test, $prepare): TestResponse {
            [, , $file] = $prepare();

            return $test->patchJson(V1::url('nodes/'.$file->id), ['name' => 'renamed.txt'])->assertStatus(428);
        }],
    ];
}

/** @return array<string, array<string, mixed>> */
function shapeOf(mixed $value): mixed
{
    if (is_object($value)) {
        $shape = [];
        foreach (get_object_vars($value) as $key => $item) {
            $shape[$key] = shapeOf($item);
        }
        ksort($shape);

        return $shape;
    }

    return is_array($value) ? ($value === [] ? [] : [shapeOf($value[0])]) : get_debug_type($value);
}

it('writes the examples from live responses when asked to', function (): void {
    if (getenv('VAULTER_OPENAPI_EXAMPLES_WRITE') !== '1') {
        $this->markTestSkipped('Set VAULTER_OPENAPI_EXAMPLES_WRITE=1 to regenerate openapi/examples.');
    }

    @mkdir(examplesDirectory(), 0o775, true);
    foreach (exampleProducers() as $name => [$operation, $status, $produce]) {
        $response = $produce();
        $headers = [];
        foreach (EXAMPLE_HEADERS as $header) {
            if ($response->headers->has($header)) {
                $headers[$header] = implode(', ', $response->headers->all($header));
            }
        }
        file_put_contents(
            examplesDirectory().'/'.$name.'.json',
            json_encode([
                'operation_id' => $operation,
                'status' => $status,
                'headers' => $headers,
                'body' => json_decode($response->getContent(), false),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n",
        );
    }

    expect(true)->toBeTrue();
});

it('has one example file per producer and no stray files', function (): void {
    $files = array_map(static fn (string $path): string => basename($path, '.json'), glob(examplesDirectory().'/*.json') ?: []);
    sort($files);
    $expected = array_keys(exampleProducers());
    sort($expected);

    expect($files)->toBe($expected);
});

it('keeps every example valid against the schema of its operation', function (): void {
    $validator = new OpenApiResponseValidator(OpenApiContract::shared());

    foreach (glob(examplesDirectory().'/*.json') ?: [] as $path) {
        $example = json_decode((string) file_get_contents($path), false);

        expect($validator->validate($example->operation_id, $example->status, (array) $example->headers, json_encode($example->body)))
            ->toBe([], basename($path));
    }
});

it('keeps every example in the shape the API answers with today', function (): void {
    foreach (exampleProducers() as $name => [$operation, $status, $produce]) {
        $example = json_decode((string) file_get_contents(examplesDirectory().'/'.$name.'.json'), false);
        $live = json_decode($produce()->getContent(), false);

        expect($example->operation_id)->toBe($operation)
            ->and($example->status)->toBe($status)
            ->and(shapeOf($example->body))->toBe(shapeOf($live), $name);
    }
});
