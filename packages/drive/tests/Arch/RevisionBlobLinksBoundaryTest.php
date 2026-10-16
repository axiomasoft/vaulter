<?php

declare(strict_types=1);

/*
 * D23, P4/P5: the revision edge and its media pin are written together, so RevisionBlobLinks is the only writer of
 * v_file_revision_blobs (through FileRevisionBlobStoreRepository) and every revision delete path detaches first.
 * The drive slices still located in core (D39) are scanned too.
 */

/** @return array<string, string> repo-relative path => code without comments */
function revisionLinksCode(string ...$roots): array
{
    $repo = dirname(__DIR__, 4);
    $files = [];
    foreach ($roots as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo.'/'.$root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= is_array($token) ? $token[1] : $token;
                }
            }
            $files[substr($file->getPathname(), strlen($repo) + 1)] = $code;
        }
    }
    ksort($files);

    return $files;
}

it('lets only RevisionBlobLinks use the edge repository', function (): void {
    $users = [];
    foreach (revisionLinksCode('packages/drive/src', 'packages/core/src', 'packages/documents/src', 'packages/media/src') as $path => $code) {
        if (str_contains($code, 'FileRevisionBlobStoreRepository') && !in_array($path, [
            'packages/drive/src/Content/RevisionBlobLinks.php',
            'packages/core/src/Repositories/Drive/FileRevisionBlobStoreRepository.php',
        ], true)) {
            $users[] = $path;
        }
    }

    expect($users)->toBe([]);
});

/** Whether $code writes a revision edge itself. */
function revisionLinksWritesEdge(string $code): bool
{
    // An edge write names the edge table or model and inserts, updates or deletes through it.
    return preg_match('/(file_revision_blobs?[\'"]\)|FileRevisionBlob::)[^;]*->(insert|insertOrIgnore|upsert|updateOrInsert|update|delete|forceDelete)\(/s', $code) === 1
        || preg_match('/->blobs\(\)->(attach|detach|sync|syncWithoutDetaching|updateExistingPivot)\(/', $code) === 1;
}

it('recognises an edge write in each of its shapes', function (string $code): void {
    expect(revisionLinksWritesEdge($code))->toBeTrue();
})->with([
    "\$db->table(\$config->table('file_revision_blobs'))->insert(\$row);",
    "\$config->model('file_revision_blob')::query()->where('blob_id', 1)->delete();",
    'FileRevisionBlob::query()->whereKey(1)->update([]);',
    '$revision->blobs()->attach($blob);',
]);

it('writes no revision edge outside the repository', function (): void {
    $writers = [];
    foreach (revisionLinksCode('packages/drive/src', 'packages/core/src', 'packages/documents/src') as $path => $code) {
        if ($path === 'packages/core/src/Repositories/Drive/FileRevisionBlobStoreRepository.php') {
            continue;
        }
        if (revisionLinksWritesEdge($code)) {
            $writers[] = $path;
        }
    }

    expect($writers)->toBe([]);
});

it('detaches the revision links on every path that deletes revisions', function (): void {
    $code = revisionLinksCode('packages/drive/src');

    foreach ([
        'packages/drive/src/Actions/Files/DeleteFileRevisionAction.php',
        'packages/drive/src/Content/FileContentLifecycle.php',
        'packages/drive/src/Actions/Drives/PurgeDriveStepAction.php',
    ] as $path) {
        expect(str_contains($code[$path], '->links->detachAll('))->toBeTrue($path);
    }
    expect($code['packages/drive/src/Actions/Files/DeleteFileRevisionAction.php'])
        ->toMatch('/->links->detachAll\(.*->revisions->delete\(/s');
});
