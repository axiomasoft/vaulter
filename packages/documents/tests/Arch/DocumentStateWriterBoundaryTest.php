<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Bypass guard: the working state and the checkpoints of a document are
 * written only by Content/DocumentStateWriter (CreateDocumentAction only inserts the
 * empty aggregate it then hands to the writer), and no document writer touches the
 * node sequence — content has its own validators.
 */

/** @return array<string, string> path relative to src/ => contents */
function documentsSources(): array
{
    $root = dirname(__DIR__, 2).'/src';
    $files = [];
    foreach ((new Finder)->files()->in($root)->name('*.php') as $file) {
        $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getContents();
    }
    ksort($files);

    return $files;
}

/**
 * @param  list<string> $allowed
 * @return list<string>
 */
function documentsSourcesMatching(string $pattern, array $allowed): array
{
    $offenders = [];
    foreach (documentsSources() as $path => $contents) {
        if (preg_match($pattern, $contents) === 1 && !in_array($path, $allowed, true) && !str_starts_with($path, 'Models/')) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

it('writes Yjs state and JSON content of documents only in DocumentStateWriter', function (): void {
    // Request validation rules name the same input keys; they never write a model.
    $offenders = array_values(array_filter(
        documentsSourcesMatching('/\'collab_state\'\s*=>|->collab_state\s*=[^=>]|->content\s*=[^=>]/', ['Content/DocumentStateWriter.php']),
        static fn (string $path): bool => !str_starts_with($path, 'Http/Requests/'),
    ));

    expect($offenders)->toBe([]);
});

it('writes the pending-state marker only in DocumentStateWriter', function (): void {
    expect(documentsSourcesMatching('/\'pending_(generation|through_seq)\'\s*=>|->pending_(generation|through_seq)\s*=[^=>]/', ['Content/DocumentStateWriter.php']))->toBe([]);
});

it('lets only the writer, aggregate creation, copy, purge and retention use the document stores', function (): void {
    expect(documentsSourcesMatching('/\bDocumentStoreRepository\b/', [
        'Content/DocumentStateWriter.php',
        'Actions/Documents/CreateDocumentAction.php',
        'Content/DocumentContentLifecycle.php',
        'Repositories/Documents/DocumentStoreRepository.php',
    ]))->toBe([])
        ->and(documentsSourcesMatching('/\bDocumentRevisionStoreRepository\b/', [
            'Content/DocumentStateWriter.php',
            // Node purge deletes the history through the store so the CAS payload references are released.
            'Content/DocumentContentLifecycle.php',
            'Actions/Revisions/UpdateRevisionRetentionAction.php',
            'Repositories/Revisions/DocumentRevisionStoreRepository.php',
        ]))->toBe([]);
});

it('keeps every document writer away from the node sequence', function (): void {
    expect(documentsSourcesMatching('/\bNodeSequence\b/', []))->toBe([]);
});
