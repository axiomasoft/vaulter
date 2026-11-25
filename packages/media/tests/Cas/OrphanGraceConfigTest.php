<?php

declare(strict_types=1);
use Vaulter\Media\Configuration\MediaOptions;

/**
 * app(\Vaulter\Media\Configuration\MediaOptions::class)->orphanGraceHours() resolution: how old a
 * bytes-without-a-row object must be before the disk-walk treats it as a deletable
 * orphan. It resolves vaulter-media.gc.orphan_grace_hours, accepts a CLI --orphan-grace
 * override, falls back to the GC grace when its own key is unset, and is FLOORED at
 * 1h so an in-flight commit can never have its just-promoted bytes swept.
 */
beforeEach(function (): void {
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('orphanGraceHours reads vaulter-media.gc.orphan_grace_hours', function (): void {
    config()->set('vaulter-media.gc.orphan_grace_hours', 72);

    expect(app(MediaOptions::class)->orphanGraceHours())->toBe(72);
});

it('orphanGraceHours: a positive override beats the config', function (): void {
    config()->set('vaulter-media.gc.orphan_grace_hours', 72);

    expect(app(MediaOptions::class)->orphanGraceHours(override: 12))->toBe(12);
});

it('orphanGraceHours falls back to gc.grace_hours when its own key is unset', function (): void {
    // Replace the whole gc block WITHOUT an orphan_grace_hours key so the lookup
    // genuinely misses it (set(..., null) would store null, which is NOT a miss).
    config()->set('vaulter-media.gc', ['grace_hours' => 36]);

    expect(app(MediaOptions::class)->orphanGraceHours())->toBe(36);
});

it('orphanGraceHours is clamped to a floor of 1h', function (): void {
    // A zero/sub-1 configured value must never let a fresh object be swept.
    config()->set('vaulter-media.gc.orphan_grace_hours', 0);
    config()->set('vaulter-media.gc.grace_hours', 0);

    expect(app(MediaOptions::class)->orphanGraceHours())->toBe(1);
});

it('orphanGraceHours: a zero override means no override (the config wins)', function (): void {
    config()->set('vaulter-media.gc.orphan_grace_hours', 48);

    // override=0 is the command's "flag absent" sentinel — it must defer to config.
    expect(app(MediaOptions::class)->orphanGraceHours(override: 0))->toBe(48);
});
