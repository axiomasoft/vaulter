<?php

declare(strict_types=1);

use Vaulter\Documents\Http\Problems\DocumentsProblemCodes;
use Vaulter\Drive\Http\Problems\DriveProblemCodes;
use Vaulter\Drive\Http\Problems\DriveSliceProblemCodes;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Http\Problems\CoreProblemCodes;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Webhooks\WebhooksProblemCodes;

require_once __DIR__.'/ProblemCatalogHelpers.php';

it('has an English and a Russian title for every code', function (string $locale): void {
    $catalog = fullProblemCatalog();

    foreach ($catalog->all() as $code => $entry) {
        expect($catalog->title($code, $locale))->toBeString()->not->toBe('', $locale.' '.$code);
        if ($code !== 'internal_error') {
            expect($catalog->detail($code, [], $locale))->toBeString($locale.' detail '.$code);
        }
    }
})->with(['en', 'ru']);

it('keeps the lang files of a package in step with its code list', function (string $package, array $sources): void {
    $codes = array_merge(...array_map(static fn (string $source): array => array_keys((new $source)->codes()), $sources));
    sort($codes);

    foreach (['en', 'ru'] as $locale) {
        $lines = require dirname(__DIR__, 4)."/packages/{$package}/resources/lang/{$locale}/problems.php";
        $keys = array_keys($lines);
        sort($keys);

        expect($keys)->toBe($codes);
    }
})->with([
    // The drive slice codes located in core keep their lines in the core lang files until P4.16 (D39).
    'core' => ['core', [CoreProblemCodes::class, DriveSliceProblemCodes::class]],
    'drive' => ['drive', [DriveProblemCodes::class]],
    'documents' => ['documents', [DocumentsProblemCodes::class]],
    'webhooks' => ['webhooks', [WebhooksProblemCodes::class]],
]);

it('lists the code and status of every ProblemException in the source tree', function (): void {
    $catalog = fullProblemCatalog()->all();
    $files = glob(dirname(__DIR__, 4).'/packages/{core,drive,documents,webhooks}/src/Exceptions/{,*/}*.php', GLOB_BRACE) ?: [];
    $seen = 0;

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        if (preg_match('/^namespace ([^;]+);.*?^(?:final )?class (\w+)[^{]*implements[^{]*ProblemException/ms', $source, $match) !== 1) {
            continue;
        }
        /** @var ProblemException $exception */
        $exception = (new ReflectionClass($match[1].'\\'.$match[2]))->newInstanceWithoutConstructor();
        $seen++;

        expect($catalog)->toHaveKey($exception->problemCode())
            ->and($catalog[$exception->problemCode()]['status'])->toBe($exception->httpStatus(), $exception::class);
    }

    expect($seen)->toBeGreaterThan(50);
});

it('lists the codes sorted, each under the module that declares it', function (): void {
    $catalog = fullProblemCatalog()->all();

    $codes = array_keys($catalog);
    $sorted = $codes;
    sort($sorted);

    expect($codes)->toBe($sorted)
        ->and($catalog['duplicate_registration']['module'])->toBe('core')
        ->and($catalog['node_name_conflict']['module'])->toBe('drive')
        ->and($catalog['upload_conflict']['module'])->toBe('drive')
        ->and($catalog['webhook_endpoint_not_found']['module'])->toBe('webhooks')
        ->and($catalog['document_state_mismatch']['module'])->toBe('documents');
});

it('refuses a code declared by two modules of a hand-built catalogue', function (): void {
    $catalog = new ProblemCatalog(
        static fn (): array => ['core' => [new CoreProblemCodes], 'acme' => [new CoreProblemCodes]],
        app('translator'),
    );

    expect(fn () => $catalog->all())->toThrow(InvalidArgumentException::class, 'declared by both the module "core" and the module "acme"');
});
