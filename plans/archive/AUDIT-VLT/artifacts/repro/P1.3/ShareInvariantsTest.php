<?php

declare(strict_types=1);

/**
 * P1.3 repro — static/source-level invariant checks for the sharing + download
 * surface. Read-only: these assertions read package source strings and assert on
 * observable structure. No Laravel app boot (the repro path is not bound to a
 * TestCase in tests/Pest.php), so every check is pure string/regex evidence.
 *
 * Run: /usr/bin/php8.4 vendor/bin/pest plans/AUDIT-VLT/artifacts/repro/P1.3
 */

$root = dirname(__DIR__, 5); // repo root (…/plans/AUDIT-VLT/artifacts/repro/P1.3 → 5 up)

$src = static function (string $rel) use ($root): string {
    $path = $root.'/'.$rel;
    expect(is_file($path))->toBeTrue("missing source: {$rel}");

    return (string) file_get_contents($path);
};

// ---------------------------------------------------------------------------
// C16 — direct-serve (presigned redirect) path exists on BOTH download entries.
// D15 requires download ALWAYS through PHP; a presigned/public URL redirect is
// not a supported mode. Presence of temporaryUrl() -> RedirectResponse is the
// forbidden path.
// ---------------------------------------------------------------------------
test('C16: DownloadController redirects to a presigned temporaryUrl (direct serve)', function () use ($src) {
    $code = $src('packages/drive/src/Http/Controllers/DownloadController.php');

    expect($code)->toContain('temporaryUrl(');
    expect($code)->toContain('new RedirectResponse($signedUrl)');
    // The redirect is gated only by is_string($signedUrl) — i.e. whenever the
    // disk can mint a signed URL the bytes leave PHP.
    expect($code)->toMatch('/is_string\(\$signedUrl\)\s*\)\s*\{\s*return new RedirectResponse/s');
});

test('C16: PublicShareController redirects to a presigned temporaryUrl (direct serve)', function () use ($src) {
    $code = $src('packages/core/src/Http/Controllers/Sharing/PublicShareController.php');

    expect($code)->toContain('temporaryUrl(');
    expect($code)->toContain('new RedirectResponse($signedUrl)');
});

test('C16: FlysystemStorageDriver returns a signed URL whenever the disk provides one', function () use ($src) {
    $code = $src('packages/media/src/Cas/FlysystemStorageDriver.php');

    // Returns null only for non-object / non-temporaryUrl disks; otherwise the URL.
    expect($code)->toContain('providesTemporaryUrls()');
    expect($code)->toContain('return $filesystem->temporaryUrl($key, $expiresAt);');
});

// ---------------------------------------------------------------------------
// C11 — object path built from the blob storage_key, never from user filename.
// ---------------------------------------------------------------------------
test('C11: stream builds the object key from $blob->storage_key, not the filename', function () use ($src) {
    foreach ([
        'packages/drive/src/Http/Controllers/DownloadController.php',
        'packages/core/src/Http/Controllers/Sharing/PublicShareController.php',
    ] as $rel) {
        $code = $src($rel);
        // The driver is always keyed by storage_key.
        expect($code)->toMatch('/key:\s*\$blob->storage_key/');
        // The filename is only the download name, never a path segment.
        expect($code)->toContain('name: $filename');
        // No parent-traversal concatenation into a key.
        expect($code)->not->toContain('..');
    }
});

// ---------------------------------------------------------------------------
// C4 — node comes from the token record, not from a {node} route param.
// ---------------------------------------------------------------------------
test('C4: ShareResolver takes the node from the share record', function () use ($src) {
    $code = $src('packages/core/src/Sharing/ShareResolver.php');
    expect($code)->toContain('$share->node()->first()');
});

test('C4: ResolveShareToken keys only on the {token} route param (no node param)', function () use ($src) {
    $code = $src('packages/core/src/Http/Middleware/ResolveShareToken.php');
    expect($code)->toContain("\$request->route('token')");
    expect($code)->not->toContain("route('node')");
});

// ---------------------------------------------------------------------------
// C5 — issuer (granted_by) rights are NOT re-verified at consumption time.
// S2.2 requires re-checking the issuer still holds rights. Its ABSENCE is the
// evidence here: the resolver never references granted_by nor the AccessResolver.
// ---------------------------------------------------------------------------
test('C5: ShareResolver never re-verifies the issuer still has rights (confused-deputy gap)', function () use ($src) {
    $code = $src('packages/core/src/Sharing/ShareResolver.php');
    expect($code)->not->toContain('granted_by');
    expect($code)->not->toContain('AccessResolver');
    expect($code)->not->toContain('->can(');
});

// ---------------------------------------------------------------------------
// C10 — public listing gated to scope=Subtree and filtered to the share node-set.
// ---------------------------------------------------------------------------
test('C10: nodes() lists only for scope=Subtree and filters to containsNode', function () use ($src) {
    $ctrl = $src('packages/core/src/Http/Controllers/Sharing/PublicShareController.php');
    expect($ctrl)->toContain('$context->scope() !== GrantScope::Subtree');
    expect($ctrl)->toContain('$context->containsNode($child)');

    $context = $src('packages/core/src/Sharing/ShareContext.php');
    // Subtree walk is bounded to the same storage tree.
    expect($context)->toContain('storage_id');
    expect($context)->toContain('isDescendantOfTarget');
});

// ---------------------------------------------------------------------------
// C8 — no default TTL: expires_at is nullable end-to-end and config.share has no
// ttl key, so a link minted without an explicit expiry never expires.
// ---------------------------------------------------------------------------
test('C8: share has no default TTL (expires_at nullable, no config ttl)', function () use ($src) {
    $req = $src('packages/core/src/Http/Requests/Sharing/StoreShareLinkRequest.php');
    expect($req)->toContain("'expires_at' => ['nullable', 'date', 'after:now']");

    $data = $src('packages/core/src/Data/Sharing/CreateShareLinkData.php');
    expect($data)->toContain('?Carbon $expiresAt = null');

    $config = $src('packages/core/config/vaulter.php');
    // The 'share' config block carries no ttl/default expiry key.
    expect($config)->toMatch('/\'share\'\s*=>\s*\[\s*\'token_generator\'/');
    $shareBlock = substr($config, (int) strpos($config, "'share' => ["), 200);
    expect($shareBlock)->not->toContain('ttl');
    expect($shareBlock)->not->toContain('expires');
});

// ---------------------------------------------------------------------------
// C1 — token is CSPRNG (Str::random -> random_bytes), 48 alnum chars ~= 285 bits.
// ---------------------------------------------------------------------------
test('C1: default token generator uses Str::random(48)', function () use ($src) {
    $code = $src('packages/core/src/Support/RandomShareTokenGenerator.php');
    expect($code)->toContain('Str::random(48)');
});
