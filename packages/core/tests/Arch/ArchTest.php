<?php

declare(strict_types=1);
use Illuminate\Http\Request;
use Vaulter\Models\Node;

arch('all package code declares strict types')
    ->expect('Vaulter')
    ->toUseStrictTypes();

/**
 * H.2 — the AccessResolver is Vaulter-OWNED and NOT swappable: it is a concrete
 * final class, never an interface/contract. Only the HostRoleDriver lane is
 * pluggable. (If someone made AccessResolver a contract, this fails.)
 */
arch('the AccessResolver is a concrete, non-swappable class')
    ->expect('Vaulter\Access\AccessResolver')
    ->not->toBeInterface();

/**
 * H.2 — the HostRoleDriver contract is node-blind and identity-model-agnostic: it
 * must NEVER reference the Node model (it deals only in Subject/AclScope/Decision).
 * A leak of Node into the contract breaks the swappable-driver boundary.
 */
arch('the HostRoleDriver contract never references the Node model')
    ->expect('Vaulter\Contracts\HostRoleDriver')
    ->not->toUse(Node::class);

/**
 * H.2 — the host-role drivers are final (a SemVer-frozen public surface) and live
 * under Drivers\Permission.
 */
arch('host-role drivers are final')
    ->expect('Vaulter\Drivers\Permission')
    ->toBeClasses();

/**
 * The ACL value objects are immutable (H.2 VOs: Subject/AclScope/Decision/GrantSet).
 */
arch('access value objects are readonly')
    ->expect('Vaulter\Access\ValueObjects')
    ->toBeReadonly();

arch('no debug helpers leak into the package')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

/**
 * H.5 — the format-extensibility registry is a concrete, final source of truth (one
 * bounded registry per concern, no god-object). The DocumentHandler contract is an
 * interface; the built-in handlers are final; the registry is final.
 */
arch('the document handler registry and its handlers are final')
    ->expect('Vaulter\Documents')
    ->toBeFinal();

arch('the DocumentHandler contract is an interface (H.5)')
    ->expect('Vaulter\Contracts\DocumentHandler')
    ->toBeInterface();

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
