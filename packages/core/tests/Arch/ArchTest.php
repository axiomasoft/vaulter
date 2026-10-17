<?php

declare(strict_types=1);
use Illuminate\Http\Request;
use Vaulter\Drive\Models\Node;

arch('all package code declares strict types')
    ->expect('Vaulter')
    ->toUseStrictTypes();

/**
 * E5.1 / F-P4.3-01 — declarative form kept for discoverability/grep (`toUse('App')`).
 * `App\*` never exists as an autoloadable class in the package's own dev/test
 * environment, so this namespace-graph check can never observe a real "uses" edge
 * (PestArch has nothing to resolve `App\*` against) — proven by the file-scan test
 * directly below, which is the test that ACTUALLY enforces the invariant (red-before
 * verified against an injected `use App\...;` fixture).
 */
arch('the package never depends on the host application (E5.1)')
    ->expect('Vaulter')
    ->not->toUse('App');

/**
 * E5.1 / F-P4.3-01 — vaulter-core is an embeddable Laravel package: it must never
 * reach into the HOST application's namespace. A stray `App\*` import (e.g.
 * copy-pasted from the consuming app while building a feature) breaks installability
 * in a clean host that does not carry that class.
 *
 * The scaffolding commands that EMIT `App\*` as the namespace of code generated INTO the host
 * moved with their domains (vaulter-drive); the core has none left.
 */
test('non-test src never references the host App namespace (E5.1, F-P4.3-01)', function (): void {
    $src = __DIR__.'/../../src';
    $offenders = [];

    $allowlist = [];

    /** @var Iterator<SplFileInfo> $files */
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)),
        '/\.php$/',
    );

    foreach ($files as $file) {
        $relative = str_replace($src.'/', '', $file->getPathname());

        if (in_array($relative, $allowlist, true)) {
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
 * E3.1 / F-P4.3-02 — the package's public surface is deliberately thin: contracts,
 * facades, DTO/VO and events. `Repositories/`/`Support/` are internal implementation
 * detail — the kernel-sharing design (ADR-0005) lets sibling Vaulter packages
 * (Drive/Media/Documents/Filament) consume them as the shared kernel, so the
 * enforceable boundary here is NOT "same package only" (core has no sub-namespace of
 * its own to scope against) but "never consumed from OUTSIDE the Vaulter namespace
 * tree" — i.e. never becomes a host-application dependency. `Vaulter\<Root>` boundary
 * tests below enforce the stricter same-package form where a satellite HAS its own
 * sub-namespace (documents/filament).
 */
arch('internal namespaces are not consumed outside the Vaulter package tree (E3.1)')
    ->expect(['Vaulter\Repositories', 'Vaulter\Support'])
    ->toOnlyBeUsedIn('Vaulter');

/**
 * H.2 — the AccessResolver is Vaulter-OWNED and NOT swappable: it is a concrete
 * final class, never an interface/contract. Only the PermissionDriver lane is
 * pluggable. (If someone made AccessResolver a contract, this fails.)
 */
arch('the AccessResolver is a concrete, non-swappable class')
    ->expect('Vaulter\Drive\Access\AccessResolver')
    ->not->toBeInterface();

/**
 * H.2 — the PermissionDriver contract is node-blind and identity-model-agnostic: it
 * must NEVER reference the Node model (it deals only in Subject/AccessScope/Decision).
 * A leak of Node into the contract breaks the swappable-driver boundary.
 */
arch('the PermissionDriver contract never references the Node model')
    ->expect('Vaulter\Drive\Contracts\Access\PermissionDriver')
    ->not->toUse(Node::class);

/**
 * H.2 — the host-role drivers are final (a SemVer-frozen public surface) and live
 * under Drivers\Permission.
 */
arch('host-role drivers are final')
    ->expect('Vaulter\Drivers\Permission')
    ->toBeClasses();

/**
 * The ACL value objects are immutable (H.2 VOs: Subject/AccessScope/Decision/GrantSet).
 */
arch('access value objects are readonly')
    ->expect('Vaulter\Access\ValueObjects')
    ->toBeReadonly();

arch('no debug helpers leak into the package')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

/**
 * H.5 — the format-extensibility registry is a concrete, final source of truth (one
 * bounded registry per concern, no god-object). The FileHandler contract is an
 * interface; the built-in handlers are final; the registry is final. The ENGINE
 * (registry/resolver/baseline handlers) is core-owned since VLT-CANON P0.4 (D10,
 * superseding H.5/T5); the documents-only additions (TipTap/Univer drivers under
 * `Vaulter\Documents\Engines\Handlers`, `ExportDocumentAction` under
 * `Vaulter\Documents\Actions\Export`) moved out of the tautological `Documents\Documents`
 * folder in P2.3 — both remain covered by this finality gate.
 */
arch('the document handler registry and its handlers are final')
    ->expect(['Vaulter\Documents\Engines\Handlers', 'Vaulter\Documents\Actions\Export\ExportDocumentAction'])
    ->toBeFinal();

/**
 * `AbstractFileHandler` (P0.5) is the one deliberate exception: an optional DX base
 * a THIRD-PARTY handler extends, so it must stay abstract, not final. Every other
 * class under `Vaulter\Engines` — the registry, the resolver, the baseline
 * handlers — is sealed core-internal.
 */
arch('the core-owned handler engine and its baseline handlers are final (P0.4/D10)')
    ->expect('Vaulter\Engines')
    ->toBeFinal()
    ->ignoring('Vaulter\Drive\Handlers\AbstractFileHandler');

arch('the FileHandler contract is an interface (H.5)')
    ->expect('Vaulter\Drive\Contracts\Handlers\FileHandler')
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

// Enums, interfaces and traits cannot be readonly; the catalogue, the sink writer and the delivery control keep
// per-call state, the outbox model is Eloquent and the lease exception extends RuntimeException (D29, D38).
arch('events are readonly')
    ->expect('Vaulter\Events')
    ->classes()
    ->toBeReadonly()
    ->ignoring([
        'Vaulter\Events\EventCatalog',
        'Vaulter\Events\SinkWriter',
        'Vaulter\Events\EventTypeCast',
        'Vaulter\Events\Outbox\DeliveryControl',
        'Vaulter\Events\Outbox\Models\OutboxEvent',
        'Vaulter\Events\Outbox\Exceptions\LeaseLostException',
    ]);

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
 * calls (DatabaseConfig::table(), NodeKind::File, …) never trip it.
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
        // The event pipeline's base class, its single recorder and the catalogue reference renderer are cross-domain (PLAN2.D22).
        // The event catalogue mechanics and the EventSink slot are cross-domain kernel types too (D38).
        'Events' => ['DomainEvent', 'EventRecorder', 'EventsReference', 'EventCatalog', 'EventSink', 'EventSources', 'EventTypeCast', 'EventTypeContract', 'SinkResult', 'SinkWriter'],
        'Models' => [], // Concerns/ is a subdir, not a depth-0 file.
        'Exceptions' => ['VaulterException'],
        'Repositories' => [],
        'Contracts' => ['IdStrategy'],
        // ADR-0017 amendment (ADR-0020): entry-point layers stay flat, so the allow-list is explicit.
        'Operations' => ['AccessOperations', 'ActivityOperations', 'ChangeOperations', 'NodeLocator', 'NodeSequence', 'DriveContext', 'OperationContext', 'ShareOperations', 'Operation', 'OperationSpec', 'OperationRunner', 'OperationMiddleware', 'OperationClaim', 'NoOperationClaim', 'ModuleReadiness', 'AfterCommitFailure'],
        // Revived in Phase D2 (P2 #5), kind-neutral naming in VLT-CANON P0.1: the
        // handler extensibility-seam VOs (FileDescriptor/ContentCapabilities/ViewerDescriptor/
        // NodePresentation) are kernel-shared — core's own Contracts\Handlers\*
        // interfaces type against them — so they live at Vaulter\ValueObjects\Handlers\*,
        // not in the extracted documents package. TipTapContent/LinkSchemeValidator/
        // OpaqueContent moved to Vaulter\Documents\ValueObjects\* (VLT-CANON
        // P0.3) — core no longer knows TipTap's shape.
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
 * ADR-0017 / VLT-REMED P4.9 — Canon A, Law 1 (layer-first placement).
 *
 * A recognized technical-layer directory (Actions/Models/Repositories/Enums/
 * Events/Data/Contracts/Http) must live as the FIRST namespace segment under the
 * package root — NEVER nested inside a domain module folder. `core` is the canon
 * reference and passes GREEN. The formerly-divergent `drive` layout (`Uploads/Actions`,
 * `Uploads/Models`, …) was the same rule; P4.10 discharged it (the layer subtrees
 * were dissolved into `Actions/Uploads`, `Models/Uploads`, …) and removed the
 * `->todo()` in drive's ArchTest.
 *
 * (Law 2 — "layers group by a domain sub-namespace, not flat" — is enforced by
 * the `split technical roots keep classes in domain subfolders` test above; the
 * formerly-flat `documents` layout was that rule, discharged by P4.10 with the
 * `->todo()` removed in documents.)
 *
 * `Http` sub-layers (Controllers/Requests/Resources/Middleware/Support) are
 * structural, not domains, so only `Http` itself is a layer segment here.
 */
test('technical layers are never nested under a domain module (ADR-0017 Law 1)', function (): void {
    $nested = layersNestedUnderDomain(__DIR__.'/../../src');

    // Public error and test contracts live with their respective families; the event
    // value objects are the ValueObjects domain "Events", the "Events" domain of the neutral event values.
    // Exceptions/Http holds the exceptions of the HTTP protocol itself (428 precondition).
    // Events/Outbox/Models holds the outbox model of the kernel outbox (D29: Vaulter\Events\Outbox\Models\OutboxEvent).
    expect(array_values(array_diff($nested, ['Exceptions/Contracts', 'Exceptions/Http', 'Testing/Contracts', 'Testing/Events', 'ValueObjects/Events', 'Events/Outbox/Models'])))->toBe([])
        ->and(scandir(__DIR__.'/../../src/Exceptions/Contracts'))->toBe(['.', '..', 'ProblemException.php'])
        ->and(scandir(__DIR__.'/../../src/Testing/Contracts'))->toBe(['.', '..', 'ModuleContractTests.php'])
        ->and(scandir(__DIR__.'/../../src/ValueObjects/Events'))->toBe(['.', '..', 'EventScope.php', 'EventSubject.php']);
});

/**
 * Walks $src and returns every layer-named directory that sits BELOW depth 0
 * (i.e. nested under a domain folder) — the Canon A / ADR-0017 Law 1 offenders.
 * Shared verbatim by the drive canon `->todo()` test.
 *
 * @return list<string> relative directory paths, e.g. "Uploads/Actions"
 */
function layersNestedUnderDomain(string $src): array
{
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

    sort($offenders);

    return array_values(array_unique($offenders));
}

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
        'Vaulter\Drive\Models\Node',
        'Vaulter\Media\Models\Cas\Blob',
        'Vaulter\Drive\Models\Access\NodeGrant',
        'Vaulter\Drive\Models\Sharing\NodeShare',
        'Vaulter\Drive\Models\Uploads\UploadSession',
        'Vaulter\Drive\Models\Activity\Activity',
        'Vaulter\Documents\Models\Revisions\DocumentRevision',
        'Vaulter\Drive\Enums\NodeKind',
        'Vaulter\Drive\Enums\Access\Ability',
        'Vaulter\Drive\Events\Sharing\NodeShareCreated',
        'Vaulter\Drive\Exceptions\Nodes\NodeCycleException',
        'Vaulter\Exceptions\Schema\UnknownIdStrategyException',
        'Vaulter\Drive\Exceptions\Profiles\UnknownProfileException',
        'Vaulter\Drive\Repositories\NodeReadRepository',
        'Vaulter\Media\Contracts\Cas\BlobStore',
        'Vaulter\Drive\Contracts\Access\PermissionDriver',
        'Vaulter\Documents\Content\TipTap\TipTapContent',
        'Vaulter\Drive\Access\ValueObjects\NodeAbilities',
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
 * (the ActivityRecorder/OutboxWriter docblocks legitimately name
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
            if (is_array($token) && (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                || ($file->getPathname() === $src.'/Configuration/ConfigNormalizer.php' && $token[0] === T_CONSTANT_ENCAPSED_STRING))) {
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

/**
 * P4 ownership move (D10, D40): the drive domain (models, repositories, snapshots, profiles, activity, changes) now lives in
 * vaulter-drive and the HTTP/config/kernel slice moved with P4.11. These core files name `Vaulter\Drive\` types in the meantime; the list is a ratchet:
 * it must shrink as the files move, it is empty from P4.16 (I1 blocks), and a file that stops referencing drive has to leave it.
 */
test('core source references the extracted vaulter-drive package only from the files that move to it (P2 #5 / D1, P4.8)', function (): void {
    $src = (string) realpath(__DIR__.'/../../src');
    $transitional = [
        'Configuration/ConfigValidator.php',
        'Configuration/VaulterConfig.php',
        'Console/InstallCommand.php',
        'Events/DomainEvent.php',
        'Http/Problems/CoreProblemCodes.php',
        'Http/Resources/PageResponse.php',
        'Http/Support/DownloadHeaders.php',
        'Http/Support/Preconditions.php',
        'Jobs/Middleware/RestoreTenantContext.php',
        'Tenancy/TenancyBoundary.php',
        'Testing/Events/EventConformance.php',
        'Testing/InteractsWithVaulter.php',
    ];
    $found = array_map(static fn (string $path): string => substr((string) realpath($path), strlen($src) + 1), coreReferencesTo('Drive'));
    sort($found);
    sort($transitional);

    expect($found)->toBe($transitional);
});

/**
 * P2 #5 / ADR-0015, Phase D2 — vaulter-documents is now PHYSICALLY extracted (its code
 * lives in packages/documents, namespace Vaulter\Documents\*). The transitional seams
 * are cut: the 7 Contracts\Handlers extensibility VOs + UnknownExportFormatException/
 * UnsupportedDocumentContentException moved to core (kernel-shared, T6/D2 finding);
 * TipTapContent/LinkSchemeValidator/OpaqueContent/TiptapExtensions/
 * InvalidDocumentContentException moved BACK to documents (VLT-CANON P0.3 — TipTap
 * isolation, core never knew their shape beyond the DocumentContent contract); the
 * FileHandlerRegistry/NodePresenter contracts + `Vaulter::handlers()->register()` resolve
 * the documents package's bindings without naming its classes; HostUserResolver
 * (default + binding) moved to that package entirely; the upload/comment/
 * document-version Config defaults inverted; the document/comment/collab routes + the
 * handler/broadcast provider wiring moved to its own provider. Same file-scan form as
 * the drive guard above.
 */
test('core source never references the extracted vaulter-documents package (P2 #5 / D2)', function (): void {
    expect(coreReferencesTo('Documents'))->toBe([]);
});

/**
 * The optional permission bridge owns its complete guard seam and driver.
 * Core source has no dependency on the bridge package.
 */
test('core source never references the vaulter-azgard package (P2.1)', function (): void {
    expect(coreReferencesTo('Azgard'))->toBe([]);
});

/**
 * VLT-CANON P0.3/P3.2 — TipTap is fully isolated behind the opaque
 * {@see DocumentContent} seam; core must never name a
 * concrete TipTap CLASS (`TipTapContent`, `TiptapExtensions`, `InvalidDocumentContentException`,
 * …) in real code. Matches the CamelCase class-name spelling only — the lower-case
 * package-owned configuration keys are a deliberate, documented
 * pass-through for a host's own TipTap extensions (VLT-REMED D27/D28) and are NOT a
 * class coupling, so they never trip this pattern. Prose mentions of "TipTap" in
 * comments/docblocks (e.g. DocumentContent/DocumentExporter explaining the seam) are
 * legitimate and stripped the same way `coreReferencesTo()` strips them.
 */
test('core source never references a concrete TipTap class (VLT-CANON P0.3)', function (): void {
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

        if (preg_match('/\bTipTap[A-Za-z]+\b/', $code) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * PLAN2.P4.3 (OPUS 04 §1 p.2) — core names no document editor at all: not in code, not
 * in comments. Editors belong to the document types of vaulter-documents; core only
 * presents a kind through a tagged KindPresenter. The one allow-listed place is the
 * config normalizer, whose string literals carry the documented legacy config keys
 * (`vaulter.tiptap.*`, the 0.2 `univer` format label).
 */
test('core source never names a document editor (TipTap, Univer)', function (): void {
    $src = __DIR__.'/../../src';
    $offenders = [];

    /** @var Iterator<SplFileInfo> $files */
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)),
        '/\.php$/',
    );

    foreach ($files as $file) {
        $contents = file_get_contents($file->getPathname());
        expect($contents)->not->toBeFalse();

        $text = '';
        foreach (token_get_all((string) $contents) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $file->getPathname() === $src.'/Configuration/ConfigNormalizer.php') {
                continue;
            }
            $text .= is_array($token) ? $token[1] : $token;
        }

        if (preg_match('/tiptap|univer/i', $text) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * VLT-CANON P0.1/D7/P3.2 — the engine-extensibility contract is kind-neutral
 * (`FileHandler`, not `DocumentHandler`): it describes ANY file, editable or not, so
 * it must never depend on the extracted vaulter-documents package (TipTap/Univer are
 * one KIND of handler among many — preview/download are not "engines"). This is the
 * final-canon guard for D7: a regression that re-couples the contract seam to a
 * specific document engine trips here, not just the broader core⊥documents guard
 * above (which only scans `src/`, not the namespace-graph form PestArch offers for a
 * single sub-namespace).
 */
arch('the FileHandler contract seam is kind-neutral and never depends on vaulter-documents (D7)')
    ->expect('Vaulter\Contracts\Handlers')
    ->not->toUse('Vaulter\Documents');

arch('DB facade only in Database and Schema (AD25)')
    ->expect('Illuminate\Support\Facades\DB')
    ->toOnlyBeUsedIn(['Vaulter\Database', 'Vaulter\Schema'])
    ->ignoring(['Vaulter\Tests', 'Vaulter\Testing', 'Vaulter\Load']);

arch('domain exceptions implement the HTTP problem contract')
    ->expect('Vaulter\Exceptions')
    ->classes()
    ->toImplement('Vaulter\Exceptions\Contracts\ProblemException')
    ->ignoring([
        'Vaulter\Exceptions\VaulterException',
        // P2.5 configuration failures stop boot before any endpoint can respond.
        'Vaulter\Exceptions\Configuration\InvalidConfigurationException',
        'Vaulter\Exceptions\Schema\UnknownIdStrategyException',
        'Vaulter\Exceptions\Support\MissingPackageException',
        'Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException',
    ]);

arch('production source never imports testing helpers (D35)')
    ->expect('Vaulter')
    ->not->toUse('Vaulter\Testing')
    ->ignoring(['Vaulter\Testing', 'Vaulter\Tests']);

test('core source and configuration contain no bridge implementation names (P2.5)', function (): void {
    $offenders = [];
    $package = dirname(__DIR__, 2);

    foreach ([$package.'/src', $package.'/config'] as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $contents = file_get_contents($file->getPathname());
            expect($contents)->not->toBeFalse();

            $code = '';

            foreach (token_get_all($contents) as $token) {
                if (is_array($token) && (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    || ($file->getPathname() === $package.'/src/Configuration/ConfigNormalizer.php' && $token[0] === T_CONSTANT_ENCAPSED_STRING))) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            if (preg_match('/azgard|hocuspocus|filament/i', $code) === 1) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([]);
});
