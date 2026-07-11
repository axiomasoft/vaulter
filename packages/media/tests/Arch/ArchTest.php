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
        'Vaulter\Access\AccessResolver',
        'Vaulter\Contracts\Access\GrantAuthoritativeDriver',
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
