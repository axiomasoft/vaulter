<?php

declare(strict_types=1);

use Vaulter\Documents\Http\Problems\DocumentsProblemCodes;
use Vaulter\Drive\Http\Problems\DriveProblemCodes;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Http\Problems\CoreProblemCodes;
use Vaulter\Http\Problems\ProblemCatalog;

/**
 * The catalogue of the whole monorepo: core plus the drive and documents sources, with
 * their lang namespaces added by path so the check does not depend on which providers
 * this suite boots.
 */
function fullProblemCatalog(): ProblemCatalog
{
    $root = dirname(__DIR__, 5).'/packages';
    $translator = app('translator');
    $translator->addNamespace('vaulter', $root.'/core/resources/lang');
    $translator->addNamespace('vaulter-drive', $root.'/drive/resources/lang');
    $translator->addNamespace('vaulter-documents', $root.'/documents/resources/lang');

    return new ProblemCatalog(
        static fn (): array => [new CoreProblemCodes, new DriveProblemCodes, new DocumentsProblemCodes],
        $translator,
    );
}

it('has an English and a Russian title for every code', function (string $locale): void {
    $catalog = fullProblemCatalog();

    foreach ($catalog->all() as $code => $entry) {
        expect($catalog->title($code, $locale))->toBeString()->not->toBe('', $locale.' '.$code);
        if ($code !== 'internal_error') {
            expect($catalog->detail($code, [], $locale))->toBeString($locale.' detail '.$code);
        }
    }
})->with(['en', 'ru']);

it('keeps the lang files of a package in step with its code list', function (string $package, string $source): void {
    $codes = array_keys((new $source)->codes());
    sort($codes);

    foreach (['en', 'ru'] as $locale) {
        $lines = require dirname(__DIR__, 5)."/packages/{$package}/resources/lang/{$locale}/problems.php";
        $keys = array_keys($lines);
        sort($keys);

        expect($keys)->toBe($codes);
    }
})->with([
    'core' => ['core', CoreProblemCodes::class],
    'drive' => ['drive', DriveProblemCodes::class],
    'documents' => ['documents', DocumentsProblemCodes::class],
]);

it('lists the code and status of every ProblemException in the source tree', function (): void {
    $catalog = fullProblemCatalog()->all();
    $files = glob(dirname(__DIR__, 5).'/packages/{core,drive,documents}/src/Exceptions/{,*/}*.php', GLOB_BRACE) ?: [];
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

it('claims a code once, core first', function (): void {
    $catalog = fullProblemCatalog()->all();

    $codes = array_keys($catalog);
    $sorted = $codes;
    sort($sorted);

    expect($codes)->toBe($sorted)
        ->and($catalog['duplicate_registration']['package'])->toBe('core')
        ->and($catalog['upload_conflict']['package'])->toBe('drive')
        ->and($catalog['document_state_mismatch']['package'])->toBe('documents');
});
