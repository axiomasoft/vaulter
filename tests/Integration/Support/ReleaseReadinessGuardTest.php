<?php

declare(strict_types=1);

/*
 * Release-readiness (green guard — doc/config/manifest-alignment
 * fixes, no behaviour delta): all four findings closed here are pinned by reading
 * the actual file each fix touched, not by memory of what "should" be there.
 */

it('splits the root CHANGELOG.md into each split PHP package before the subtree split', function (): void {
    $root = dirname(__DIR__, 3);
    $workflow = file_get_contents($root.'/.github/workflows/monorepo-split.yml');
    $builder = file_get_contents($root.'/scripts/release/build-artifacts.php');

    // The root CHANGELOG.md now ships inside the canonical prepared tree (public channel), so the ZIP and the split commit
    // carry the same bytes; the publisher only materializes that prepared commit.
    expect($workflow)->not->toBeFalse()
        ->and($builder)->not->toBeFalse()
        ->and($builder)->toContain("':CHANGELOG.md'");

    $preparePos = strpos($workflow, 'build-artifacts.php --version');
    $materializePos = strpos($workflow, '--materialize-split');

    expect($preparePos)->not->toBeFalse()
        ->and($materializePos)->not->toBeFalse()
        ->and($preparePos)->toBeLessThan($materializePos);
});

it('declares a deprecation policy with a concrete removal horizon in CONTRIBUTING.md', function (): void {
    $contributing = file_get_contents(dirname(__DIR__, 3).'/CONTRIBUTING.md');

    expect($contributing)->not->toBeFalse()
        ->and($contributing)->toContain('## Deprecation policy')
        ->and($contributing)->toContain('@deprecated')
        // The policy must name a concrete lifetime, not just "eventually removed".
        ->and($contributing)->toMatch('/at least one minor release/i')
        ->and($contributing)->toMatch('/next.{0,10}major/i');
});

it('keeps @tiptap/pm as a peer dependency in @vaulter/vue package.json', function (): void {
    $json = json_decode(
        file_get_contents(dirname(__DIR__, 3).'/packages/vue/package.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($json['peerDependencies'])->toHaveKey('@tiptap/pm')
        ->and($json['dependencies'])->not->toHaveKey('@tiptap/pm');

    // vue was already a peer; it must stay one.
    expect($json['peerDependencies'])->toHaveKey('vue');

    // Adopted the versioned release channels and the vue source manifest at 1.0.0.
    expect($json['version'])->toBe('1.0.0');
});

it('formulates README\'s vaulter:install line as "asks to run", not "runs them"', function (): void {
    $readme = file_get_contents(dirname(__DIR__, 3).'/README.md');

    expect($readme)->not->toBeFalse()
        ->and($readme)->toContain('vaulter:install   # publishes config, asks to run the migrations')
        ->and($readme)->not->toContain('vaulter:install   # publishes config, runs the migrations');
});
