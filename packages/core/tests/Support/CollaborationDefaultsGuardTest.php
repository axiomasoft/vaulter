<?php

declare(strict_types=1);

use Vaulter\Support\Config;

/*
 * VLT-REMED P3.1 (W3/B11) — doc/ADR-alignment guard tests (D19: green
 * characterization, red-before is structurally impossible here — no behaviour
 * changed, only a stale fallback/doc were corrected to match the already-correct
 * code).
 */

it('Config::collaborationDriverName() falls back to hocuspocus when the config key is entirely absent, F-P3.6-01', function (): void {
    config()->offsetUnset('vaulter.collaboration.driver');

    expect(Config::collaborationDriverName())->toBe('hocuspocus');
});

it('the config/vaulter.php default transport is hocuspocus (unchanged by P3.1 — the ADR/docs were the drift), F-P3.6-01', function (): void {
    expect(config('vaulter.collaboration.driver'))->toBe('hocuspocus');
});

it('README marks the five feature-flagged capabilities as opt-in, F-P2.5-06', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 4).'/README.md');

    $features = ['Documents', 'Versions', 'Real-time collaboration', 'Comments', 'Share links'];

    foreach ($features as $feature) {
        $marker = "**{$feature}**";
        $position = strpos($readme, $marker);

        expect($position)->not->toBeFalse("README no longer lists the '{$feature}' bullet at all.");

        // The opt-in note must sit within the same bullet line (before the next
        // ' - **' bullet marker), not merely somewhere later in the file.
        $nextBullet = strpos($readme, "\n- **", $position);
        $bulletEnd = $nextBullet === false ? strlen($readme) : $nextBullet;
        $bulletText = substr($readme, $position, $bulletEnd - $position);

        expect($bulletText)->toContain('opt-in')
            ->and($bulletText)->toContain("config('vaulter.features.");
    }
});
