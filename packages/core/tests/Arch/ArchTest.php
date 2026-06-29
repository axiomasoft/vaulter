<?php

declare(strict_types=1);
use Illuminate\Http\Request;

arch('all package code declares strict types')
    ->expect('Vaulter')
    ->toUseStrictTypes();

arch('no debug helpers leak into the package')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('actions are final')
    ->expect('Vaulter\Actions')
    ->toBeFinal();

arch('value objects are readonly')
    ->expect('Vaulter\ValueObjects')
    ->toBeReadonly();

arch('data transfer objects are readonly')
    ->expect('Vaulter\Data')
    ->toBeReadonly();

arch('events are readonly')
    ->expect('Vaulter\Events')
    ->toBeReadonly();

arch('contracts are interfaces')
    ->expect('Vaulter\Contracts')
    ->toBeInterfaces();

arch('actions never depend on the HTTP request')
    ->expect('Vaulter\Actions')
    ->not->toUse(Request::class);

arch('repositories never depend on the HTTP request')
    ->expect('Vaulter\Repositories')
    ->not->toUse(Request::class);

/**
 * H.4 arch-ban: recursive descendant aggregation in request paths is fatal on
 * MariaDB (no correlated CTEs) and degrades on Postgres. No non-test src file may
 * call whereHas('descendants') or withCount('descendants'); subtree work goes
 * through NodeReadRepository (withMaxDepth) or direct EXISTS / denorm counters.
 */
test('non-test src never uses whereHas/withCount on descendants (H.4)', function (): void {
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

        // Strip comments + docblocks so the ban only fires on real code (the H.4
        // rule is about query calls, not prose explaining the rule).
        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        if (preg_match("/(whereHas|withCount)\\(\\s*['\"]descendants['\"]/", $code) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
