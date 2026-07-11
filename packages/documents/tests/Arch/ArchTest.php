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
