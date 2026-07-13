<?php

declare(strict_types=1);

// The drive package is strict-typed end to end.
arch('strict types')
    ->expect('Vaulter\Drive')
    ->toUseStrictTypes();

// T11 — drive and documents are independent siblings over core: nothing under
// Vaulter\Drive may reach into the documents engine. The Drive node read shape that
// embedded the document handler descriptor moved to core (Http\Resources\Drive\
// NodeResource) precisely so this stays true.
arch('drive never depends on documents')
    ->expect('Vaulter\Drive')
    ->not->toUse('Vaulter\Documents');

// E5.1 / F-P4.3-01 — declarative form kept for discoverability/grep (`toUse('App')`);
// see the file-scan test below (and core's ArchTest.php) for why this namespace-graph
// form cannot itself observe a real "uses" edge in this dev/test environment.
arch('the package never depends on the host application (E5.1)')
    ->expect('Vaulter\Drive')
    ->not->toUse('App');

/**
 * E5.1 / F-P4.3-01 — vaulter-drive is an embeddable Laravel package: it must never
 * reach into the HOST application's namespace. File scan (not
 * `expect(...)->not->toUse('App')`) because `App\*` is never an autoloadable class in
 * the package's own dev/test environment (red-before proven on core's ArchTest.php).
 */
test('non-test src never references the host App namespace (E5.1, F-P4.3-01)', function (): void {
    $src = __DIR__.'/../../src';
    $offenders = [];

    /** @var Iterator<SplFileInfo> $files */
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)),
        '/\.php$/',
    );

    foreach ($files as $file) {
        $contents = file_get_contents($file->getPathname());

        if ($contents === false) {
            continue;
        }

        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        if (preg_match('/\\bApp\\\\/', $code) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * ADR-0017 / VLT-REMED P4.9 + P4.10 — Canon A, Law 1 (layer-first placement).
 *
 * `drive` was DIVERGENT: the `Uploads/` module carried its own nested layer tree
 * (`Uploads/Actions`, `Uploads/Models`, `Uploads/Repositories`, `Uploads/Http`,
 * `Uploads/Enums`, `Uploads/Data`, `Uploads/Contracts`) — a layer nested UNDER a
 * domain, the inverse of the core canon. P4.10 DISCHARGED it: those layer
 * subtrees were dissolved into the package-root layers with an `Uploads` domain
 * sub-namespace (`Actions/Uploads`, `Models/Uploads`, `Repositories/Uploads`,
 * `Enums/Uploads`, `Data/Uploads`, `Contracts/Uploads`, `Http/<sub>/Uploads`); the
 * flat upload service classes stayed as the `Uploads/` domain-service folder
 * (mirrors `core/src/Sharing`). This test now asserts the CANON and passes GREEN;
 * the `->todo()` was removed by P4.10.
 *
 * Red-before proven (P4.9): before the move, this body failed with offenders
 * `Uploads/Actions`, `Uploads/Contracts`, `Uploads/Data`, `Uploads/Enums`,
 * `Uploads/Http`, `Uploads/Http/Controllers`, `Uploads/Http/Requests`,
 * `Uploads/Http/Resources`, `Uploads/Models`, `Uploads/Repositories`.
 *
 * VLT-CANON P2.3 further split the `Uploads/` domain-service folder by role:
 * `*Handler`/`*Manager` orchestrators (`TusUploadHandler`, `S3MultipartHandler`,
 * `UploadSessionManager`) stayed; the pure helpers (`MagicByteSniffer`,
 * `StagedUpload`, `UploadModels`) moved to `Support/Uploads` — still outside the
 * `$layers` list below, so this test is unaffected.
 */
test('technical layers are never nested under a domain module (ADR-0017 Law 1)', function (): void {
    $src = __DIR__.'/../../src';
    $layers = ['Actions', 'Models', 'Repositories', 'Enums', 'Events', 'Data', 'Contracts', 'Http'];
    $offenders = [];

    // SELF_FIRST so DIRECTORIES are yielded (the default LEAVES_ONLY mode only
    // yields files, which would make this dir-structure check an inert no-op).
    /** @var Iterator<SplFileInfo> $dirs */
    $dirs = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($dirs as $dir) {
        if (!$dir->isDir()) {
            continue;
        }

        $relative = str_replace($src.'/', '', $dir->getPathname());
        $segments = explode('/', $relative);

        foreach ($segments as $index => $segment) {
            if ($index > 0 && in_array($segment, $layers, true)) {
                $offenders[] = $relative;
                break;
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});
