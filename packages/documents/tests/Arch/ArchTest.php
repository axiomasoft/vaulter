<?php

declare(strict_types=1);

// The documents package is strict-typed end to end.
arch('strict types')
    ->expect('Vaulter\Documents')
    ->toUseStrictTypes();

// D6 / P4.8 — documents sits on drive in the package graph (core <- media <- drive <- documents): the access model it
// authorizes with (AccessResolver, OperationGuard, Ability) is drive's since the P4.8 move. It still never reaches media.
arch('documents depends on drive but never directly on media (D6)')
    ->expect('Vaulter\Documents')
    ->not->toUse(['Vaulter\Media']);

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

    // Scaffolding commands legitimately emit `App\*` as a STRING (the namespace of
    // generated host code), never as a real import/dependency of the package itself.
    $allowlist = [
        'Console/MakeDocumentTypeCommand.php',
        'Console/MakeExporterCommand.php',
    ];

    /** @var Iterator<SplFileInfo> $files */
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)),
        '/\.php$/',
    );

    foreach ($files as $file) {
        if (in_array(str_replace($src.'/', '', $file->getPathname()), $allowlist, true)) {
            continue;
        }

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
 * ADR-0017 / VLT-REMED P4.9 + P4.10 — Canon A, Law 2 (domain sub-namespace within layers).
 *
 * `documents` was DIVERGENT: its layers were layer-first (Law 1 ✅) but FLAT — classes
 * sat directly in the layer root with no domain sub-namespace, unlike core's
 * `Actions/Sharing`, `Enums/Webhooks`, … . P4.10 DISCHARGED it: the flat classes were
 * grouped under domain sub-namespaces (`Actions/Comments`, `Actions/Documents`,
 * `Actions/Revisions`, `Enums/Export`, `Models/Revisions`, …).
 * This test now asserts the CANON (no non-allow-listed class flat at a layer root)
 * and passes GREEN; the `->todo()` was removed by P4.10.
 *
 * Mirrors core's green `split technical roots keep classes in domain subfolders`
 * test. Red-before proven (P4.9): before the move, this body failed with offenders
 * in Actions (9), Data (6), Events (6), Enums (4), Repositories (4), Models (2).
 */
test('layers group classes under a domain sub-namespace, not flat (ADR-0017 Law 2)', function (): void {
    $src = __DIR__.'/../../src';

    // layer root => bare class names allowed to remain flat at the root (canon
    // cross-cutting citizens): the event types of the module, contributed to the event catalogue (D38).
    $rootsWithAllowed = [
        'Actions' => [],
        'Models' => [],
        'Repositories' => [],
        'Enums' => ['DocumentEventType'],
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
});

arch('DB facade is routed through VaulterDatabase (AD25)')
    ->expect('Vaulter\Documents')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('domain exceptions implement the HTTP problem contract')
    ->expect('Vaulter\Documents\Exceptions')
    ->classes()
    ->toImplement('Vaulter\Exceptions\Contracts\ProblemException');

arch('production source never imports testing helpers (D35)')
    ->expect('Vaulter\Documents')
    ->not->toUse('Vaulter\Documents\Testing')
    ->ignoring(['Vaulter\Documents\Testing', 'Vaulter\Documents\Tests']);
