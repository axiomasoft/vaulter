<?php

declare(strict_types=1);

/**
 * P1.8 repro — static/source-level invariant checks for the secrets/config/HTTP
 * surface. Read-only: assertions read package source strings and assert on
 * observable structure. No Laravel app boot (the repro path is not bound to a
 * TestCase in tests/Pest.php — same constraint as P1.3/P1.5/P1.7), so every check
 * is pure string/regex evidence over the shipped source.
 *
 * Covers the plan's repro-required checks C10 / C11 / C12 / C15.
 *
 * Run: /usr/bin/php8.4 vendor/bin/pest plans/AUDIT-VLT/artifacts/repro/P1.8
 */

$root = dirname(__DIR__, 5); // repo root

$src = static function (string $rel) use ($root): string {
    $path = $root.'/'.$rel;
    expect(is_file($path))->toBeTrue("missing source: {$rel}");

    return (string) file_get_contents($path);
};

// ---------------------------------------------------------------------------
// C15 — NO throttle/rate-limit on any package route or controller.
// The public share / tus / collab / webhook-receiver surfaces are mounted with
// only ['api'] + Resolve* middleware; there is no throttle middleware anywhere.
// ---------------------------------------------------------------------------
test('C15: route files declare no throttle middleware', function () use ($src) {
    foreach ([
        'packages/core/routes/api.php',
        'packages/drive/routes/drive.php',
        'packages/documents/routes/documents.php',
    ] as $rel) {
        expect($src($rel))->not->toContain('throttle');
    }
});

test('C15: public share group carries only ResolveShareToken (no throttle)', function () use ($src) {
    $api = $src('packages/core/routes/api.php');
    // The public share routes exist and are wrapped in ResolveShareToken only.
    expect($api)->toContain('ResolveShareToken');
    expect($api)->toContain("share/{token}");
    expect($api)->not->toContain('throttle');
});

// ---------------------------------------------------------------------------
// C11 — default middleware stack is ['api'] with NO auth; the config ships it so.
// Mitigation to record: controllers require an authenticated actor (fail-closed
// 403), documented in docs/reference/rest-api.md.
// ---------------------------------------------------------------------------
test('C11: config default middleware is [api] without auth', function () use ($src) {
    $cfg = $src('packages/core/config/vaulter.php');
    expect($cfg)->toMatch("/'middleware'\s*=>\s*\['api'\]/");
    // No 'auth' token injected into the default middleware array.
    expect($cfg)->not->toMatch("/'middleware'\s*=>\s*\[[^\]]*auth[^\]]*\]/");
});

test('C11: rest-api reference documents unauthenticated -> 403 (fail-closed)', function () use ($src) {
    $doc = $src('docs/reference/rest-api.md');
    expect($doc)->toContain('unauthenticated request');
    expect($doc)->toContain('403');
});

// ---------------------------------------------------------------------------
// C12 — VaulterFormRequest::authorize() returns true unconditionally; real
// authorization must therefore happen in the controller (AccessResolver/Policy).
// ---------------------------------------------------------------------------
test('C12: VaulterFormRequest::authorize returns true unconditionally', function () use ($src) {
    $code = $src('packages/core/src/Http/Requests/VaulterFormRequest.php');
    expect($code)->toMatch('/public function authorize\(\): bool\s*\{\s*return true;\s*\}/');
});

// ---------------------------------------------------------------------------
// C10 — routes without a per-object guard from P0.3 §2.1 are subject-scoped
// filters (still authorized), NOT unguarded. Evidence: the three listed methods
// narrow the result set to the actor's accessible node set.
// ---------------------------------------------------------------------------
test('C10: subject-scoped routes narrow to the actor accessible set', function () use ($src) {
    $core = $src('packages/drive/src/Http/Controllers/NodeController.php');
    // sharedWith + narrowToAccessible are the subject-scoping seams for
    // shared / search / trashed.
    expect($core)->toContain('sharedWith');
    expect($core)->toContain('narrowToAccessible');
});

test('C10: webhook-endpoint management is fail-closed by default ability', function () use ($src) {
    // Default config ability is a string ('vaulter.webhooks.manage'), not null —
    // an undefined host gate denies. The open branch fires ONLY on explicit null.
    $cfg = $src('packages/core/config/vaulter.php');
    expect($cfg)->toContain("'vaulter.webhooks.manage'");
    $policy = $src('packages/core/src/Policies/WebhookEndpointPolicy.php');
    expect($policy)->toContain('$ability === null');
});
