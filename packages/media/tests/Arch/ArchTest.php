<?php

declare(strict_types=1);

// The media package is strict-typed end to end.
arch('strict types')
    ->expect('Vaulter\Media')
    ->toUseStrictTypes();

// The CAS layer stays ACL-agnostic: nothing under Vaulter\Media may reach into the
// core access-control machinery. This is the structural guard behind decision D3
// (per-file ACL lives in core; media is a pure content-addressed blob layer).
arch('media never touches the access layer')
    ->expect('Vaulter\Media')
    ->not->toUse([
        'Vaulter\Drive\Access\AccessResolver',
        'Vaulter\Drive\Contracts\Access\GrantAuthoritativeDriver',
    ]);

// E5.1 / F-P4.3-01 — declarative form kept for discoverability/grep (`toUse('App')`);
// see the file-scan test below (and core's ArchTest.php) for why this namespace-graph
// form cannot itself observe a real "uses" edge in this dev/test environment.
arch('the package never depends on the host application (E5.1)')
    ->expect('Vaulter\Media')
    ->not->toUse('App');

/**
 * E5.1 / F-P4.3-01 — vaulter-media is an embeddable Laravel package: it must never
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
 * VLT-REL P2.2 — empty-surface guard for the 0.1.0 freeze. This package ships NO public
 * contract interface (`src/Contracts/**`), so the frozen surface records that emptiness:
 * an accidentally-added public contract (which would need its own snapshot freeze + a D#
 * per AMEND) reddens the build instead of slipping into the tag unfrozen. The sibling
 * packages with real contracts (core/drive/documents/azguard) carry PublicApiSnapshotTest.
 */
test('the contracts of the package are the CAS seams under Contracts/Cas and nothing else (D6)', function (): void {
    $contractsDir = realpath(__DIR__.'/../../src/Contracts');
    $outside = [];
    $notInterfaces = [];

    /** @var Iterator<SplFileInfo> $files */
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($contractsDir, FilesystemIterator::SKIP_DOTS)),
        '/\.php$/',
    );

    foreach ($files as $file) {
        if (!str_starts_with($file->getPathname(), $contractsDir.DIRECTORY_SEPARATOR.'Cas'.DIRECTORY_SEPARATOR)) {
            $outside[] = $file->getPathname();
        }
        if (preg_match('/^interface\s/m', (string) file_get_contents($file->getPathname())) !== 1) {
            $notInterfaces[] = $file->getPathname();
        }
    }

    expect($contractsDir)->not->toBeFalse()
        ->and($outside)->toBe([])
        ->and($notInterfaces)->toBe([]);
});

arch('DB facade is routed through VaulterDatabase (AD25)')
    ->expect('Vaulter\Media')
    ->not->toUse('Illuminate\Support\Facades\DB');

// D35: production code cannot import fixtures or other Testing namespace classes.
test('production src never imports a Testing namespace', function (): void {
    $src = __DIR__.'/../../src';
    $offenders = [];

    /** @var Iterator<SplFileInfo> $files */
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)),
        '/\.php$/',
    );

    foreach ($files as $file) {
        // A shipped Testing helper is allowed to depend on other testing helpers.
        if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Testing'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if ($contents === false) {
            continue;
        }

        foreach (token_get_all($contents) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }

            if (in_array('Testing', explode('\\', $token[1]), true)) {
                $offenders[] = $file->getPathname();
                break;
            }
        }
    }

    expect($offenders)->toBe([]);
});
