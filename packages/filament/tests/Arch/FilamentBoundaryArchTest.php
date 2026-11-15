<?php

declare(strict_types=1);

/*
 * The panel is a client of the public drive entry point and handle. Reaching a model, an
 * Action, a Repository, a query or the AccessResolver would let a resource decide access on its
 * own, so none of them may appear anywhere in the package, nor an Eloquent Builder to read with.
 */
arch('the panel never uses drive models, Actions, Repositories, the AccessResolver or an Eloquent Builder')
    ->expect('Vaulter\Filament')
    ->not->toUse([
        'Vaulter\Actions',
        'Vaulter\Drive\Actions',
        'Vaulter\Documents\Actions',
        'Vaulter\Repositories',
        'Vaulter\Drive\Repositories',
        'Vaulter\Documents\Repositories',
        'Vaulter\Drive\Access\AccessResolver',
        'Vaulter\Drive\Queries',
        'Vaulter\Drive\Models',
        'Vaulter\Documents\Models',
        'Illuminate\Database\Eloquent\Builder',
    ]);

arch('the retired panel access scope is gone')
    ->expect('Vaulter\Filament\Support\PanelAccessScope')
    ->not->toBeUsed();

test('no panel source builds an Eloquent access scope', function (): void {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!str_ends_with($file->getPathname(), '.php')) {
            continue;
        }
        if (preg_match('/constrainToAccessible|candidateNodeIds|PanelAccessScope/', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});

test('no panel source imports a drive model, repository, action or Eloquent Builder (I9)', function (): void {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!str_ends_with($file->getPathname(), '.php')) {
            continue;
        }
        if (preg_match('/Vaulter\\\\Drive\\\\(Models|Repositories|Actions|Queries)\\\\|Eloquent\\\\Builder|AccessResolver/', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
