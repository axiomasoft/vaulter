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
