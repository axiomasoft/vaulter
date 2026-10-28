<?php

declare(strict_types=1);

/*
 * P4.7 / B21 — Release-readiness (D19 green guard — doc/config/manifest-alignment
 * fixes, no behaviour delta): all four findings closed here are pinned by reading
 * the actual file each fix touched, not by memory of what "should" be there.
 */

it('splits the root CHANGELOG.md into each split PHP package before the subtree split, F-P2.1-06', function (): void {
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

it('declares a deprecation policy with a concrete removal horizon in CONTRIBUTING.md, F-P2.1-07', function (): void {
    $contributing = file_get_contents(dirname(__DIR__, 3).'/CONTRIBUTING.md');

    expect($contributing)->not->toBeFalse()
        ->and($contributing)->toContain('## Deprecation policy')
        ->and($contributing)->toContain('@deprecated')
        // The policy must name a concrete lifetime, not just "eventually removed".
        ->and($contributing)->toMatch('/at least one minor release/i')
        ->and($contributing)->toMatch('/next.{0,10}major/i');
});

it('moves yjs and @tiptap/pm to peerDependencies in @vaulter/vue package.json, F-P2.5-07', function (): void {
    $json = json_decode(
        file_get_contents(dirname(__DIR__, 3).'/packages/vue/package.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    foreach (['yjs', '@tiptap/pm'] as $dualInstanceRiskPackage) {
        // ADR-0031 moved Yjs ownership to vue-collaboration; ProseMirror stays in vue.
        $owner = $dualInstanceRiskPackage === 'yjs' ? 'vue-collaboration' : 'vue';
        $json = json_decode(file_get_contents(dirname(__DIR__, 3).'/packages/'.$owner.'/package.json'), associative: true, flags: JSON_THROW_ON_ERROR);
        expect($json['peerDependencies'])->toHaveKey($dualInstanceRiskPackage)
            ->and($json['dependencies'])->not->toHaveKey($dualInstanceRiskPackage);
    }

    // vue was already a peer; it must stay one.
    expect($json['peerDependencies'])->toHaveKey('vue');

    // version:0.0.0 is a documented, deliberate pre-release state (F-P2.5-07
    // rationale) — not something this fix silently mutates into a made-up number.
    expect($json['version'])->toBe('0.0.0');
});

it('formulates README\'s vaulter:install line as "asks to run", not "runs them", F-P2.5-04', function (): void {
    $readme = file_get_contents(dirname(__DIR__, 3).'/README.md');

    expect($readme)->not->toBeFalse()
        ->and($readme)->toContain('vaulter:install   # publishes config, asks to run the migrations')
        ->and($readme)->not->toContain('vaulter:install   # publishes config, runs the migrations');
});
