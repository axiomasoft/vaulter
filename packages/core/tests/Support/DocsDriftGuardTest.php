<?php

declare(strict_types=1);
use Symfony\Component\Finder\Finder;

/*
 * P4.8 / B19 — Docs-drift (D19 green guard — mechanical doc↔code alignment
 * fixes, no behaviour delta): each guard below reads the actual doc file / the
 * actual code surface directly (grep-equivalent), never asserts by memory of
 * what "should" be documented. Red-before is confirmed via `git stash` of the
 * non-test files this item touched (docs/**, CONTRIBUTING.md), then reverting.
 */

it('documents vaulter:prune-webhooks in commands.md, F-P2.5-01', function (): void {
    $doc = file_get_contents(dirname(__DIR__, 4).'/docs/reference/commands.md');

    expect($doc)->not->toBeFalse()
        ->and($doc)->toContain('vaulter:prune-webhooks');
});

it('documents the four Drive-native node-comment routes in rest-api.md, F-P2.5-02', function (): void {
    $doc = file_get_contents(dirname(__DIR__, 4).'/docs/reference/rest-api.md');

    expect($doc)->not->toBeFalse()
        ->and($doc)->toContain('GET nodes/{node}/comments')
        ->and($doc)->toContain('POST nodes/{node}/comments')
        ->and($doc)->toContain('POST nodes/{node}/comments/{comment}/resolve')
        ->and($doc)->toContain('DELETE nodes/{node}/comments/{comment}');
});

it('has an errors.md heading for every self::docs() anchor actually used in code, F-P2.5-03', function (): void {
    // Ground truth: every anchor a real exception references via self::docs(),
    // grepped from the code — not a hand-maintained list that can go stale.
    $anchors = [];

    foreach ((new Finder)->files()->in([
        dirname(__DIR__, 4).'/packages/core/src',
        dirname(__DIR__, 4).'/packages/drive/src',
        dirname(__DIR__, 4).'/packages/media/src',
        dirname(__DIR__, 4).'/packages/documents/src',
        dirname(__DIR__, 4).'/packages/filament/src',
        dirname(__DIR__, 4).'/packages/permissions-azgard/src',
    ])->name('*.php') as $file) {
        if (preg_match_all("/self::docs\\('([a-z-]+)'\\)/", $file->getContents(), $matches)) {
            foreach ($matches[1] as $anchor) {
                $anchors[$anchor] = true;
            }
        }
    }

    expect($anchors)->not->toBeEmpty();

    $errorsDoc = file_get_contents(dirname(__DIR__, 4).'/docs/guide/errors.md');
    expect($errorsDoc)->not->toBeFalse();

    preg_match_all('/^## ([a-z-]+)$/m', $errorsDoc, $headingMatches);
    $headings = array_flip($headingMatches[1]);

    foreach (array_keys($anchors) as $anchor) {
        expect(array_key_exists($anchor, $headings))->toBeTrue(
            "errors.md is missing a heading for anchor [{$anchor}], used by self::docs('{$anchor}') in code — the Docs: link would 404.",
        );
    }
});

it('does not document the removed DocumentNotFoundException anchor in errors.md, F-P2.5-03', function (): void {
    $errorsDoc = file_get_contents(dirname(__DIR__, 4).'/docs/guide/errors.md');

    expect($errorsDoc)->not->toBeFalse()
        ->and($errorsDoc)->not->toContain('## document-not-found');
});

it('documents gc.orphan_grace_hours in configuration.md, F-P2.5-05', function (): void {
    $doc = file_get_contents(dirname(__DIR__, 4).'/docs/reference/configuration.md');

    expect($doc)->not->toBeFalse()
        ->and($doc)->toContain('gc.orphan_grace_hours');
});

it('does not advertise the dead permission_resolver config key, F-P2.4-01', function (): void {
    $guideConfig = file_get_contents(dirname(__DIR__, 4).'/docs/guide/configuration.md');
    $vaulterConfig = file_get_contents(dirname(__DIR__, 4).'/packages/core/config/vaulter.php');

    expect($guideConfig)->not->toBeFalse()
        ->and($vaulterConfig)->not->toBeFalse()
        // The key must genuinely be absent from the shipped config (ground truth)…
        ->and($vaulterConfig)->not->toContain("'permission_resolver'")
        // …and the guide must no longer advertise it as a working config entry.
        ->and($guideConfig)->not->toContain("'permission_resolver' => null");
});

it('does not document the removed legacy PermissionResolver/CollaborationDriver contracts, F-P2.1-02', function (): void {
    $doc = file_get_contents(dirname(__DIR__, 4).'/docs/reference/contracts.md');
    $facade = file_get_contents(dirname(__DIR__, 4).'/docs/reference/facade.md');

    expect($doc)->not->toBeFalse()
        ->and($doc)->not->toContain('Vaulter\Contracts\Access\PermissionResolver')
        ->and($doc)->not->toContain('Vaulter\Contracts\Collaboration\CollaborationDriver')
        ->and($facade)->not->toBeFalse()
        ->and($facade)->not->toContain('resolvePermissionsWith')
        ->and($facade)->not->toContain('permissionResolver()');
});

it('documents all 7 previously-undocumented public contracts in contracts.md, F-P2.1-03', function (): void {
    $doc = file_get_contents(dirname(__DIR__, 4).'/docs/reference/contracts.md');

    expect($doc)->not->toBeFalse();

    foreach ([
        'GrantAuthoritativeDriver',
        'ListableContentStore',
        'ListableStorageDriver',
        'DocumentContent',
        'HandlerRegistry',
        'NodeHandlerResolver',
        'AzgardBackedUser',
    ] as $contract) {
        expect($doc)->toContain($contract);
    }
});

it('has a CORS section in rest-api.md covering the REST surface (not just collaboration), F-P1.8-04', function (): void {
    $doc = file_get_contents(dirname(__DIR__, 4).'/docs/reference/rest-api.md');

    expect($doc)->not->toBeFalse()
        ->and($doc)->toContain('## CORS')
        ->and($doc)->toMatch('/allowed_origins/');
});

it('states the real PHPStan level (max) in CONTRIBUTING.md, F-P2.5-08', function (): void {
    $contributing = file_get_contents(dirname(__DIR__, 4).'/CONTRIBUTING.md');
    $phpstanNeon = file_get_contents(dirname(__DIR__, 4).'/phpstan.neon');

    expect($contributing)->not->toBeFalse()
        ->and($phpstanNeon)->not->toBeFalse()
        ->and($phpstanNeon)->toContain('level: max')
        ->and($contributing)->not->toContain('level 8')
        ->and($contributing)->toContain('level max');
});
