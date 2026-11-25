<?php

declare(strict_types=1);
use Symfony\Component\Finder\Finder;

/*
 * Docs-drift (green guard — mechanical doc↔code alignment
 * fixes, no behaviour delta): each guard below reads the actual doc file / the
 * actual code surface directly (grep-equivalent), never asserts by memory of
 * what "should" be documented. Red-before is confirmed via `git stash` of the
 * non-test files this item touched (docs/**, CONTRIBUTING.md), then reverting.
 */

it('documents vaulter:webhooks:prune in commands.md', function (): void {
    $doc = file_get_contents(dirname(__DIR__, 3).'/docs/reference/commands.md');

    expect($doc)->not->toBeFalse()
        ->and($doc)->toContain('vaulter:webhooks:prune');
});

it('documents the four node-comment routes in rest-api.md', function (): void {
    $doc = file_get_contents(dirname(__DIR__, 3).'/docs/reference/rest-api.md');

    expect($doc)->not->toBeFalse()
        ->and($doc)->toContain('GET v1/nodes/{node}/comments')
        ->and($doc)->toContain('POST v1/nodes/{node}/comments')
        ->and($doc)->toContain('POST v1/nodes/{node}/comments/{comment}/resolve')
        ->and($doc)->toContain('DELETE v1/nodes/{node}/comments/{comment}');
});

it('has an errors.md heading for every self::docs() anchor actually used in code', function (): void {
    // Ground truth: every anchor a real exception references via self::docs(),
    // grepped from the code — not a hand-maintained list that can go stale.
    $anchors = [];

    foreach ((new Finder)->files()->in([
        dirname(__DIR__, 3).'/packages/core/src',
        dirname(__DIR__, 3).'/packages/drive/src',
        dirname(__DIR__, 3).'/packages/media/src',
        dirname(__DIR__, 3).'/packages/documents/src',
        dirname(__DIR__, 3).'/packages/filament/src',
        dirname(__DIR__, 3).'/packages/azguard/src',
    ])->name('*.php') as $file) {
        if (preg_match_all("/self::docs\\('([a-z-]+)'\\)/", $file->getContents(), $matches)) {
            foreach ($matches[1] as $anchor) {
                $anchors[$anchor] = true;
            }
        }
    }

    expect($anchors)->not->toBeEmpty();

    $errorsDoc = file_get_contents(dirname(__DIR__, 3).'/docs/guides/errors.md');
    expect($errorsDoc)->not->toBeFalse();

    preg_match_all('/^## ([a-z-]+)$/m', $errorsDoc, $headingMatches);
    $headings = array_flip($headingMatches[1]);

    foreach (array_keys($anchors) as $anchor) {
        expect(array_key_exists($anchor, $headings))->toBeTrue(
            "errors.md is missing a heading for anchor [{$anchor}], used by self::docs('{$anchor}') in code — the Docs: link would 404.",
        );
    }
});

it('does not document the removed DocumentNotFoundException anchor in errors.md', function (): void {
    $errorsDoc = file_get_contents(dirname(__DIR__, 3).'/docs/guides/errors.md');

    expect($errorsDoc)->not->toBeFalse()
        ->and($errorsDoc)->not->toContain('## document-not-found');
});

it('documents gc.orphan_grace_hours in configuration.md', function (): void {
    $doc = file_get_contents(dirname(__DIR__, 3).'/docs/reference/config.md');

    expect($doc)->not->toBeFalse()
        ->and($doc)->toContain('gc.orphan_grace_hours');
});

it('does not advertise the dead permission_resolver config key', function (): void {
    $guideConfig = file_get_contents(dirname(__DIR__, 3).'/docs/guides/configuration.md');
    $vaulterConfig = file_get_contents(dirname(__DIR__, 3).'/packages/core/config/vaulter.php');

    expect($guideConfig)->not->toBeFalse()
        ->and($vaulterConfig)->not->toBeFalse()
        // The key must genuinely be absent from the shipped config (ground truth)…
        ->and($vaulterConfig)->not->toContain("'permission_resolver'")
        // …and the guide must no longer advertise it as a working config entry.
        ->and($guideConfig)->not->toContain("'permission_resolver' => null");
});

it('does not document the removed legacy PermissionResolver/CollaborationDriver contracts', function (): void {
    $doc = file_get_contents(dirname(__DIR__, 3).'/docs/reference/php-api.md');
    $facade = file_get_contents(dirname(__DIR__, 3).'/docs/reference/php-api.md');

    expect($doc)->not->toBeFalse()
        ->and($doc)->not->toContain('Vaulter\Contracts\Access\PermissionResolver')
        ->and($doc)->not->toContain('Vaulter\Contracts\Collaboration\CollaborationDriver')
        ->and($facade)->not->toBeFalse()
        ->and($facade)->not->toContain('resolvePermissionsWith')
        ->and($facade)->not->toContain('permissionResolver()');
});

it('documents all 7 previously-undocumented public contracts in contracts.md', function (): void {
    $doc = file_get_contents(dirname(__DIR__, 3).'/docs/reference/php-api.md');

    expect($doc)->not->toBeFalse();

    foreach ([
        'GrantAuthoritativeDriver',
        'ListableContentStore',
        'ListableStorageDriver',
        'DocumentContent',
        'FileHandlerRegistry',
        'NodePresenter',
        'AzGuardBackedUser',
    ] as $contract) {
        expect($doc)->toContain($contract);
    }
});

it('has a CORS section in rest-api.md covering the REST surface (not just collaboration)', function (): void {
    $doc = file_get_contents(dirname(__DIR__, 3).'/docs/reference/rest-api.md');

    expect($doc)->not->toBeFalse()
        ->and($doc)->toContain('## CORS')
        ->and($doc)->toMatch('/allowed_origins/');
});

it('states the real PHPStan level (max) in CONTRIBUTING.md', function (): void {
    $contributing = file_get_contents(dirname(__DIR__, 3).'/CONTRIBUTING.md');
    $phpstanNeon = file_get_contents(dirname(__DIR__, 3).'/phpstan.neon');

    expect($contributing)->not->toBeFalse()
        ->and($phpstanNeon)->not->toBeFalse()
        ->and($phpstanNeon)->toContain('level: max')
        ->and($contributing)->not->toContain('level 8')
        ->and($contributing)->toContain('level max');
});
