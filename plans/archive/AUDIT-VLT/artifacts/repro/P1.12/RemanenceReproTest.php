<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\Permission;
use Vaulter\Sharing\ShareResolution;
use Vaulter\Sharing\ShareResolver;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Sharing\ShareTestHelpers;

/**
 * P1.12 remanence repro — runtime (boots the package TestCase + in-memory sqlite
 * through tests/Pest.php: this path is bound to Access via the $core.'/Access' glob?
 * No — it is bound below explicitly). Read-only: asserts observable behaviour of the
 * SHIPPED code, changes nothing.
 *
 * Run: /usr/bin/php8.4 vendor/bin/pest plans/AUDIT-VLT/artifacts/repro/P1.12
 */
uses(Vaulter\Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter.features.share_links', true);
});

// ---------------------------------------------------------------------------
// C1 — a TRASHED node (trashed_at set, deleted_at null) is STILL downloadable:
// the AccessResolver never consults trashed_at, and the {node} route binding uses
// findOrFail() (soft-delete scope excludes deleted_at, NOT trashed_at), so a node
// sitting in the trash still resolves and still authorizes Download.
// ---------------------------------------------------------------------------
it('C1: AccessResolver->can(Download) still returns TRUE on a trashed node (trash ignored by authz)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'in-trash');
    AccessTestHelpers::grant(
        node: $node,
        level: GrantLevel::Reader,
        effect: Effect::Allow,
        scope: GrantScope::Subtree,
        permissions: Permission::Read->value,
        subjectId: 1,
    );

    $actor = AccessTestHelpers::user(1);

    // Sanity: live node is downloadable.
    $liveDecision = app(AccessResolver::class)->can($actor, Ability::Download, $node);

    // Put the node in the trash (soft-trash column, NOT Laravel deleted_at).
    $node->forceFill(['trashed_at' => Carbon::now()])->save();
    $node->refresh();

    $trashedDecision = app(AccessResolver::class)->can($actor, Ability::Download, $node);

    dump("C1 live_can_download=".var_export($liveDecision, true)
        ." trashed_at=".($node->trashed_at?->toDateTimeString() ?? 'null')
        ." deleted_at=".($node->deleted_at?->toDateTimeString() ?? 'null')
        ." trashed_can_download=".var_export($trashedDecision, true));

    // The defect: authz does not fall to deny for a trashed node.
    expect($liveDecision)->toBeTrue()
        ->and($trashedDecision)->toBeTrue();
});

it('C1: {node} route binding uses findOrFail (no trashed filter) and DownloadController never checks trashed_at', function (): void {
    $root = dirname(__DIR__, 5);

    $binding = (string) file_get_contents($root.'/packages/core/src/VaulterServiceProvider.php');
    // The binding resolves a trashed node by design (comment says so) via findOrFail.
    expect($binding)->toContain("Route::bind('node'")
        ->and($binding)->toContain('findOrFail($value)');

    $dl = (string) file_get_contents($root.'/packages/drive/src/Http/Controllers/DownloadController.php');
    // No trash guard anywhere in the download path.
    expect($dl)->not->toContain('trashed_at')
        ->and($dl)->not->toContain('trashed(');

    $resolver = (string) file_get_contents($root.'/packages/core/src/Access/AccessResolver.php');
    expect($resolver)->not->toContain('trashed_at');
});

// ---------------------------------------------------------------------------
// C2 — a share minted on a live node STOPS working the moment the node is trashed
// (item-safety in ShareResolver), NOT after the 30-day retention. This is the
// correct behaviour; the repro pins it.
// ---------------------------------------------------------------------------
it('C2: a share on a node moved to the trash resolves to NotFound immediately', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'shared-then-trashed');
    ShareTestHelpers::share($node, token: 'c2-token', permissions: Permission::Read->value);

    // Live: the share resolves.
    $live = app(ShareResolver::class)->resolve('c2-token');
    expect($live->isOk())->toBeTrue();

    // Trash the node — the share must die at once.
    $node->forceFill(['trashed_at' => Carbon::now()])->save();

    $afterTrash = app(ShareResolver::class)->resolve('c2-token');

    dump("C2 live_ok=".var_export($live->isOk(), true)
        ." after_trash_resolution=".$afterTrash->resolution->name);

    expect($afterTrash->resolution)->toBe(ShareResolution::NotFound);
});

// ---------------------------------------------------------------------------
// C8 — export is STRICTLY single-node and authorized per node; there is no
// folder/bulk export path that could authorize once at a root and leak a deny
// child. Proven by the route surface + service signature.
// ---------------------------------------------------------------------------
it('C8: no folder/bulk export exists — only nodes/{node}/export, authorized per node', function (): void {
    $root = dirname(__DIR__, 5);

    $routes = (string) file_get_contents($root.'/packages/documents/routes/documents.php');
    expect($routes)->toContain("nodes/{node}/export");
    // No multi-node / folder / recursive export route.
    expect($routes)->not->toContain('folders/{node}/export');
    expect($routes)->not->toContain('export-tree');

    $svc = (string) file_get_contents($root.'/packages/documents/src/Documents/NodeExportService.php');
    // export() takes a single Node, resolves only that node's head content.
    expect($svc)->toContain('public function export(Node $node, string $format)');

    $ctrl = (string) file_get_contents($root.'/packages/documents/src/Http/Controllers/DocumentNodeController.php');
    // The export method guards View on that exact node before exporting.
    expect($ctrl)->toMatch('/public function export\([^)]*\)\s*:\s*JsonResponse\s*\{\s*\$this->guardDocument\(\$request, Ability::View, \$node\)/s');
});

// ---------------------------------------------------------------------------
// C10 — a TRASHED document node can still be exported: guardDocument checks kind +
// AccessResolver (which ignores trash), and the binding resolves the trashed node.
// Same root cause as C1, on the export surface.
// ---------------------------------------------------------------------------
it('C10: export guard does not exclude a trashed node (no trashed_at check on the export path)', function (): void {
    $root = dirname(__DIR__, 5);

    $ctrl = (string) file_get_contents($root.'/packages/documents/src/Http/Controllers/DocumentNodeController.php');
    // guardDocument only checks kind + access; no trash exclusion.
    expect($ctrl)->not->toContain('trashed_at')
        ->and($ctrl)->not->toContain('->trashed()');

    $svc = (string) file_get_contents($root.'/packages/documents/src/Documents/NodeExportService.php');
    expect($svc)->not->toContain('trashed_at');
});

// ---------------------------------------------------------------------------
// C11 — no authorization-decision cache exists in the Access / Policies layers, so
// there is no cache-key tenant/identity leak to audit.
// ---------------------------------------------------------------------------
it('C11: Access and Policies layers contain no decision cache', function (): void {
    $root = dirname(__DIR__, 5);

    $files = array_merge(
        glob($root.'/packages/core/src/Access/*.php') ?: [],
        glob($root.'/packages/core/src/Access/**/*.php') ?: [],
        glob($root.'/packages/core/src/Policies/*.php') ?: [],
    );

    $hits = [];
    foreach ($files as $file) {
        $code = (string) file_get_contents($file);
        if (str_contains($code, 'Cache::') || preg_match('/\bcache\(/', $code)) {
            $hits[] = basename($file);
        }
    }

    dump('C11 cache_hits='.($hits === [] ? 'NONE' : implode(',', $hits)));

    expect($hits)->toBe([]);
});
