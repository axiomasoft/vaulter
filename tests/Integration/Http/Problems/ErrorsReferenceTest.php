<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Vaulter\Http\Problems\ErrorsReference;

require_once __DIR__.'/ProblemCatalogHelpers.php';

/**
 * The reference pages carry a block generated from the code catalogue; a page that
 * drifted from the codes fails here and in vaulter:errors:reference --check.
 */
function errorsReference(): ErrorsReference
{
    return new ErrorsReference(fullProblemCatalog());
}

$pages = [
    'en' => [dirname(__DIR__, 4).'/docs/reference/errors.md', 'en'],
    'ru' => [dirname(__DIR__, 4).'/docs/ru/reference/errors.md', 'ru'],
];

it('keeps the generated block of each page current', function (string $path, string $locale): void {
    $page = file_get_contents($path);
    expect($page)->toContain(ErrorsReference::START, ErrorsReference::END);

    expect(errorsReference()->apply($page, $locale))->toBe($page);
})->with($pages);

it('gives every code a heading, the anchor of its type URI', function (): void {
    $block = errorsReference()->render('en');

    foreach (array_keys(fullProblemCatalog()->all()) as $code) {
        expect($block)->toContain("\n### `{$code}`\n");
    }
});

it('fails the check when a page drifts and succeeds on a current page', function (): void {
    $reference = errorsReference();
    $current = "intro\n".$reference->block('en')."\noutro";
    $stale = str_replace('Node name conflict', 'Node name clash', $current);
    $dir = sys_get_temp_dir().'/vaulter-errors-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/current.md', $current);
    file_put_contents($dir.'/stale.md', $stale);
    $this->app->instance(ErrorsReference::class, $reference);

    try {
        $this->artisan('vaulter:errors:reference', ['--check' => true, '--path' => [$dir.'/current.md']])->assertExitCode(0);
        $this->artisan('vaulter:errors:reference', ['--check' => true, '--path' => [$dir.'/stale.md']])->assertExitCode(1);
        expect(file_get_contents($dir.'/stale.md'))->toBe($stale);

        $this->artisan('vaulter:errors:reference', ['--path' => [$dir.'/stale.md']])->assertExitCode(0);
        expect(file_get_contents($dir.'/stale.md'))->toBe($current);

        file_put_contents($dir.'/bare.md', 'no markers');
        $this->artisan('vaulter:errors:reference', ['--check' => true, '--path' => [$dir.'/bare.md']])->assertExitCode(1);
    } finally {
        array_map(unlink(...), glob($dir.'/*.md') ?: []);
        rmdir($dir);
    }
});

it('returns null for a page without markers', function (): void {
    expect(errorsReference()->apply('plain', 'en'))->toBeNull();
});

it('prints the code table for the OpenAPI document with --openapi and writes nothing', function (): void {
    $reference = errorsReference();
    $this->app->instance(ErrorsReference::class, $reference);
    $dir = sys_get_temp_dir().'/vaulter-errors-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/page.md', 'untouched');

    try {
        expect(Artisan::call('vaulter:errors:reference', ['--openapi' => true, '--path' => [$dir.'/page.md']]))->toBe(0)
            ->and(trim(Artisan::output()))->toBe($reference->openApi());
        expect(file_get_contents($dir.'/page.md'))->toBe('untouched');
    } finally {
        unlink($dir.'/page.md');
        rmdir($dir);
    }

    $fragment = $reference->openApi();
    $codes = array_keys(fullProblemCatalog()->all());
    expect($fragment)->toStartWith('x-vaulter-problems:')
        ->and($fragment)->toContain("\n  stale_write: {status: 412, retryable: after_refetch, receipt: cacheable, module: core}\n")
        ->and($fragment)->toContain("\nexamples:\n- ".$codes[0]."\n");
});
