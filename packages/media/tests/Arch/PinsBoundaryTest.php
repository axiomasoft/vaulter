<?php

declare(strict_types=1);

/*
 * Boundaries of the pins: media is a dependency of the drive and reads none of its types or tables (the collector
 * reads only the pins), and inside media nothing but BlobPinStore writes v_blob_references. That no other package
 * names the table is checked over the whole repository by tests/Repo/Arch/BoundaryScanTest.php: this suite also runs
 * as a split package, without its siblings.
 */

/** @return array<string, string> package-relative path => contents of every PHP file under the given directories */
function pinsBoundaryFiles(string ...$dirs): array
{
    $package = dirname(__DIR__, 2);
    $files = [];
    foreach ($dirs as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($package) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
    }
    ksort($files);

    return $files;
}

/** The code without its comments: a comment may name the table, only code can write it. */
function pinsBoundaryCode(string $contents): string
{
    $code = '';
    foreach (token_get_all($contents) as $token) {
        if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $code .= is_array($token) ? $token[1] : $token;
        }
    }

    return $code;
}

it('keeps every drive type and table out of the media sources', function (): void {
    $offenders = [];
    foreach (pinsBoundaryFiles('src') as $path => $contents) {
        if (preg_match('/FileRevision|file_revision|Vaulter\\\\(Drive|Models\\\\Drive|Repositories\\\\Drive)\\\\/', $contents, $match) === 1) {
            $offenders[] = $path.': '.$match[0];
        }
    }

    expect($offenders)->toBe([]);
});

it('lets only BlobPinStore write the pins table', function (): void {
    $allowed = [
        'src/Cas/BlobPinStore.php', // the only writer
        'src/Schema/MediaSchema.php', // creates and drops the table
    ];
    $users = [];
    foreach (pinsBoundaryFiles('src', 'database') as $path => $contents) {
        if (str_contains(pinsBoundaryCode($contents), 'blob_references') && !in_array($path, $allowed, true) && !str_ends_with($path, '_create_v_blob_references_table.php')) {
            $users[] = $path;
        }
    }

    expect($users)->toBe([]);
});

it('points the only foreign key of the media schema from the pin to the blob', function (): void {
    $schema = pinsBoundaryFiles('src')['src/Schema/MediaSchema.php'];
    preg_match_all('/->on\(\$this->helpers->table\(\'([a-z_]+)\'\)\)/', $schema, $targets);

    expect($targets[1])->toBe(['blobs'])
        ->and($schema)->toContain('->restrictOnDelete()');
});
