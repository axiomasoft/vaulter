<?php

declare(strict_types=1);

// The documents package is strict-typed end to end.
arch('strict types')
    ->expect('Vaulter\Documents')
    ->toUseStrictTypes();

// T6 — documents requires ONLY core in production; it never depends on drive or
// media (both are optional siblings over the same kernel, not peers of documents).
arch('documents never depends on drive or media (T6 — documents requires only core)')
    ->expect('Vaulter\Documents')
    ->not->toUse(['Vaulter\Drive', 'Vaulter\Media']);

// E5.1 / F-P4.3-01 — declarative form kept for discoverability/grep (`toUse('App')`);
// see the file-scan test below (and core's ArchTest.php) for why this namespace-graph
// form cannot itself observe a real "uses" edge in this dev/test environment.
arch('the package never depends on the host application (E5.1)')
    ->expect('Vaulter\Documents')
    ->not->toUse('App');

/**
 * E5.1 / F-P4.3-01 — vaulter-documents is an embeddable Laravel package: it must never
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
 * E3.1 / F-P4.3-02 — documents HAS its own package sub-namespace, so the public-API
 * boundary is enforceable at full strength here: `Repositories/`/`Support/` are
 * internal implementation detail and must never be referenced from outside
 * `Vaulter\Documents` itself (verified: no other package currently references
 * `Vaulter\Documents\Repositories`/`Vaulter\Documents\Support`).
 */
arch('internal namespaces are not used outside the package (E3.1)')
    ->expect(['Vaulter\Documents\Repositories', 'Vaulter\Documents\Support'])
    ->toOnlyBeUsedIn('Vaulter\Documents');

/**
 * ADR-0017 / VLT-REMED P4.9 — Canon A, Law 2 (domain sub-namespace within layers).
 *
 * `documents` DIVERGES: its layers are layer-first (Law 1 ✅) but FLAT — classes
 * sit directly in the layer root with no domain sub-namespace (`Actions/*.php`,
 * `Enums/*.php`, `Events/*.php`, `Models/*.php`, `Repositories/*.php`,
 * `Data/*.php`), unlike core's `Actions/Sharing`, `Enums/Webhooks`, … . This test
 * asserts the CANON (no non-allow-listed class flat at a layer root) and is RED on
 * the current layout by design — it is the executable spec of the P4.10 move
 * (introduce domain sub-namespaces: `Actions/Documents`, `Actions/Comments`,
 * `Events/Comments`, `Enums/Export`, …). Marked `->todo()` so the P4.9 suite stays
 * green; P4.10 makes it pass and removes the `->todo()`.
 *
 * Mirrors core's green `split technical roots keep classes in domain subfolders`
 * test. Red-before proven (P4.9): running this body without `->todo()` fails with
 * offenders in Actions (9), Data (6), Events (6), Enums (4), Repositories (4),
 * Models (2).
 */
test('layers group classes under a domain sub-namespace, not flat (ADR-0017 Law 2)', function (): void {
    $src = __DIR__.'/../../src';

    // layer root => bare class names allowed to remain flat at the root (canon
    // cross-cutting citizens; documents currently declares none).
    $rootsWithAllowed = [
        'Actions' => [],
        'Models' => [],
        'Repositories' => [],
        'Enums' => [],
        'Events' => [],
        'Data' => [],
    ];

    $offenders = [];

    foreach ($rootsWithAllowed as $root => $allowed) {
        $dir = "{$src}/{$root}";

        if (!is_dir($dir)) {
            continue;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if (!str_ends_with($entry, '.php')) {
                continue;
            }

            $class = substr($entry, 0, -4);

            if (!in_array($class, $allowed, true)) {
                $offenders[] = "{$root}/{$entry}";
            }
        }
    }

    expect($offenders)->toBe([]);
})->todo('unblocked by P4.10 — ADR-0017 Canon A Law 2 (introduce domain sub-namespaces in flat layers)');
