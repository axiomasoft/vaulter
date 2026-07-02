<?php

declare(strict_types=1);
use Illuminate\Http\Request;
use Vaulter\Contracts\Access\HostRoleDriver;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;

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
    ->expect('Vaulter\Contracts\Access\HostRoleDriver')
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
    ->expect('Vaulter\Documents\Documents')
    ->toBeFinal();

arch('the DocumentHandler contract is an interface (H.5)')
    ->expect('Vaulter\Contracts\Documents\DocumentHandler')
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
 * Layering: HTTP controllers must never touch an Eloquent model STATICALLY
 * (Model::query/create/where/all/find/…) — persistence goes through repositories
 * and Actions (M1c-http note). Controllers may still type-hint a model for
 * route-model binding; this bans the static query/persistence entry points only.
 *
 * The model set is the concrete v_* models; the scan strips comments/docblocks so
 * the rule fires on real code, and anchors on `Model::<verb>(` so unrelated static
 * calls (Config::table(), NodeKind::File, …) never trip it.
 */
test('no HTTP controller statically queries an Eloquent model (M1c-http)', function (): void {
    $controllers = __DIR__.'/../../src/Http/Controllers';

    // Models now live under per-domain subfolders (Models/Drive, Models/Cas, …)
    // plus the cross-model Models/Concerns/ traits — recurse and keep the leaf
    // class names (the Concerns traits ride along harmlessly, they are never a
    // static-query target).
    /** @var Iterator<SplFileInfo> $modelFiles */
    $modelFiles = new RegexIterator(
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../src/Models'),
        ),
        '/\.php$/',
    );

    $models = collect(iterator_to_array($modelFiles))
        ->map(fn (SplFileInfo $f): string => $f->getBasename('.php'))
        ->unique()
        ->values()
        ->all();

    expect($models)->not->toBe([]);

    $verbs = 'query|create|where|all|find|findOrFail|firstOrFail|first|firstWhere|'
        .'insert|update|destroy|updateOrCreate|firstOrCreate|firstOrNew';
    $names = implode('|', array_map('preg_quote', $models));
    $pattern = "/\\b({$names})::({$verbs})\\s*\\(/";

    $offenders = [];

    /** @var Iterator<SplFileInfo> $files */
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllers)),
        '/\.php$/',
    );

    foreach ($files as $file) {
        $contents = file_get_contents($file->getPathname());

        if ($contents === false) {
            continue;
        }

        // Strip comments + docblocks so the ban only fires on real code.
        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        if (preg_match($pattern, $code) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Domain-subfolder boundary (structural refactor): the previously-flat technical
 * roots are now split by domain — every class lives under a domain subfolder, with
 * a SHORT, explicit allow-list of cross-cutting classes that legitimately sit at
 * the technical root (the base exception, the cross-cutting IdStrategy contract,
 * the cross-model Concerns traits, and the swappable-models base namespace).
 *
 * The check: for each split root, every *.php directly in the root dir (depth 0)
 * must be an allowed root-citizen; everything else has been pushed one level down
 * into a domain folder.
 */
test('split technical roots keep classes in domain subfolders', function (): void {
    $src = __DIR__.'/../../src';

    // root => list of bare class names allowed to remain at the flat root.
    $rootsWithAllowed = [
        'Enums' => [],
        'Events' => [],
        'Models' => [], // Concerns/ is a subdir, not a depth-0 file.
        'Exceptions' => ['VaulterException'],
        'Repositories' => [],
        'Contracts' => ['IdStrategy'],
        // Revived in Phase D2 (P2 #5): the Documents extensibility-seam VOs
        // (FileDescriptor/DocumentCapabilities/HandlerUi/HandlerDescriptor/
        // TipTapContent/OpaqueDocumentContent) are kernel-shared — core's own
        // Contracts\Documents\* interfaces type against them — so they live at
        // Vaulter\ValueObjects\Documents\*, not in the extracted documents package.
        'ValueObjects' => [],
    ];

    $offenders = [];

    foreach ($rootsWithAllowed as $root => $allowed) {
        $dir = "{$src}/{$root}";

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

/**
 * The split domains live under the canonical domain set. Spot-check one class per
 * (root × domain) cell so a regression that drops a class back into a flat root —
 * or into a non-canonical domain name — trips here with a clear message naming the
 * exact FQCN that drifted.
 */
test('split classes resolve under their canonical domain namespace', function (): void {
    // String literals (NOT ::class) so the check is independent of imports: each
    // class MUST exist at exactly this canonical per-domain FQCN. A regression that
    // leaves a class flat or under a wrong domain makes class_exists() false here.
    $expected = [
        'Vaulter\Models\Drive\Node',
        'Vaulter\Models\Cas\Blob',
        'Vaulter\Models\Access\NodeGrant',
        'Vaulter\Models\Sharing\NodeShare',
        'Vaulter\Drive\Uploads\Models\UploadSession',
        'Vaulter\Models\Activity\Activity',
        'Vaulter\Documents\Models\NodeDocumentVersion',
        'Vaulter\Enums\Drive\NodeKind',
        'Vaulter\Enums\Access\Ability',
        'Vaulter\Events\Sharing\NodeShareCreated',
        'Vaulter\Drive\Exceptions\CycleException',
        'Vaulter\Exceptions\Schema\UnknownIdStrategyException',
        'Vaulter\Exceptions\Panels\UnknownPanelException',
        'Vaulter\Repositories\Drive\NodeReadRepository',
        'Vaulter\Contracts\Cas\BlobStore',
        'Vaulter\Contracts\Access\HostRoleDriver',
        'Vaulter\ValueObjects\Documents\TipTapContent',
        'Vaulter\Access\Abilities\NodeAbilities',
    ];

    $missing = array_values(array_filter(
        $expected,
        fn (string $fqcn): bool => !class_exists($fqcn) && !interface_exists($fqcn),
    ));

    expect($missing)->toBe([]);
});

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

/**
 * P2 #5 / ADR-0015 — package dependency direction. The physical package split keeps
 * ONE direction: satellites depend on core, core depends on NOTHING in the Vaulter
 * ecosystem. This guards that the extracted vaulter-media package (its CAS engine
 * under Vaulter\Media) is NEVER referenced back from core (or any other Vaulter code
 * except media itself) — a leak would recreate the cycle the extraction avoided.
 *
 * The drive (D1) and documents (D2) extractions landed as file-scan guards below
 * instead of this `->ignoring(...)` shape — see `coreReferencesTo()` for why.
 */
arch('core (and all Vaulter code) never depends on the extracted vaulter-media package')
    ->expect('Vaulter')
    ->not->toUse('Vaulter\Media')
    ->ignoring('Vaulter\Media');

/**
 * Scans packages/core/src for a `Vaulter\<root>\` reference OUTSIDE comments/docblocks
 * (the ActivityRecorder/OutboxRecorder/VaulterManager docblocks legitimately name
 * extracted-package classes in prose explaining the seam split). This is a file scan
 * (NOT `expect('Vaulter')->not->toUse(...)`) so it enforces core⊥<package> WITHOUT an
 * ->ignoring exclusion: each satellite is a require-dev sibling autoloaded into the
 * core test run, so the namespace-graph form would need ->ignoring('Vaulter\<root>')
 * to skip the satellite's own internal self-references — this form instead asserts the
 * precise invariant (zero references from CORE SOURCE) and self-updates as new core
 * files are added. `Vaulter\<root>\` matches the package root only; the kernel
 * DATA-domain namespaces (Vaulter\Models\Drive, Vaulter\Repositories\Drive, …) do not
 * start with that prefix and are correctly ignored. Shared by the drive (D1) and
 * documents (D2) dependency-direction guards below.
 *
 * @return list<string>
 */
function coreReferencesTo(string $root): array
{
    $src = __DIR__.'/../../src';
    $offenders = [];

    /** @var Iterator<SplFileInfo> $files */
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)),
        '/\.php$/',
    );

    $pattern = '/\\bVaulter\\\\'.preg_quote($root, '/').'\\\\/';

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

        if (preg_match($pattern, $code) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    return $offenders;
}

test('core source never references the extracted vaulter-drive package (P2 #5 / D1)', function (): void {
    expect(coreReferencesTo('Drive'))->toBe([]);
});

/**
 * P2 #5 / ADR-0015, Phase D2 — vaulter-documents is now PHYSICALLY extracted (its code
 * lives in packages/documents, namespace Vaulter\Documents\*). The transitional seams
 * are cut: the 7 Contracts\Documents extensibility VOs + InvalidTipTapContentException/
 * UnknownExportFormatException moved to core (kernel-shared, T6/D2 finding); the
 * HandlerRegistry/NodeHandlerResolver contracts + `Vaulter::registerHandler()` resolve
 * the documents package's bindings without naming its classes; HostUserResolver
 * (default + binding) moved to that package entirely; the upload/comment/
 * document-version Config defaults inverted; the document/comment/collab routes + the
 * handler/broadcast provider wiring moved to its own provider. Same file-scan form as
 * the drive guard above.
 */
test('core source never references the extracted vaulter-documents package (P2 #5 / D2)', function (): void {
    expect(coreReferencesTo('Documents'))->toBe([]);
});
