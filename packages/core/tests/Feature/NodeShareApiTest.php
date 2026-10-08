<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\Permission;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\Sharing\ShareTestHelpers;

/**
 * The bearer share-link HTTP surface (§2.7 / M2c). The OWNER endpoints run under
 * the identity ACL (Ability::Share gates minting); the PUBLIC endpoints run behind
 * ResolveShareToken with NO identity ACL — a share grants exactly its bits. These
 * are SEPARATE channels and the tests prove they never bleed into each other.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter.features.share_links', true);
    // The owner endpoints authorise via the real grant resolver (a Share grant is
    // the only thing that opens the door).
    config()->set('vaulter.access.permission_driver', 'database');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

/**
 * The opaque node id SharedNodeResource projects (P1.2 / F-P1.3-05) — the SAME
 * deterministic hash_hmac the resource itself computes, so a test can assert the
 * exact response value without reaching into the resource's internals.
 */
function expectedOpaqueNodeId(int|string $id): string
{
    return substr(hash_hmac('sha256', (string) $id, (string) config('app.key')), 0, 32);
}

// ─────────────────────────────────────── owner surface ────────────────────────

it('lets an owner with the Share ability mint a link and returns the raw token once', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'shareable');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, scope: GrantScope::Item); // grants SHARE bit

    $response = $this->postJson('api/vaulter/nodes/'.$node->getKey().'/shares', [
        'permissions' => (Permission::View->value | Permission::Download->value),
    ])->assertCreated();

    // The raw token + URL come back exactly once.
    $token = $response->json('data.token');
    expect($token)->toBeString()->not->toBe('');
    $response->assertJsonPath('data.url', '/share/'.$token);

    // Only the hash is stored.
    $row = NodeShare::query()->firstOrFail();
    expect($row->token_hash)->toBe(hash('sha256', $token));
});

it('stamps a default expiry when none is supplied, never minting an immortal link (F-P1.3-03)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'ttl-default');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, scope: GrantScope::Item);

    $this->postJson('api/vaulter/nodes/'.$node->getKey().'/shares', [
        'permissions' => (Permission::View->value | Permission::Download->value),
    ])->assertCreated();

    $row = NodeShare::query()->firstOrFail();
    expect($row->expires_at)->not->toBeNull();
    // Config default is 72h — allow slack for test wall-clock drift, not for a
    // materially different TTL.
    expect($row->expires_at->diffInMinutes(now()->addHours(72), absolute: true))->toBeLessThan(5);
});

it('rejects an explicit expiry above the configured ceiling (F-P1.3-03)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'ttl-ceiling');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, scope: GrantScope::Item);

    // Config max is 720h (30 days) — 400 days is well past it.
    $this->postJson('api/vaulter/nodes/'.$node->getKey().'/shares', [
        'permissions' => (Permission::View->value | Permission::Download->value),
        'expires_at' => now()->addDays(400)->toIso8601String(),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('expires_at');

    expect(NodeShare::query()->count())->toBe(0);
});

it('403s an owner WITHOUT the Share ability', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'locked');
    // Reader does NOT carry the SHARE bit.
    AccessTestHelpers::grant($node, RoleRegistry::READER, scope: GrantScope::Item);

    $this->postJson('api/vaulter/nodes/'.$node->getKey().'/shares', [
        'permissions' => (Permission::View->value | Permission::Download->value),
    ])->assertStatus(403);
});

it('lists shares WITHOUT ever exposing the raw token or hash', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'listed');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, scope: GrantScope::Item);
    ShareTestHelpers::share($node, token: 'listing-secret');

    $response = $this->getJson('api/vaulter/nodes/'.$node->getKey().'/shares')->assertOk();

    $first = $response->json('data.0');
    expect($first)->toHaveKey('token_prefix')
        ->and($first)->not->toHaveKey('token')
        ->and($first)->not->toHaveKey('token_hash')
        ->and($first)->not->toHaveKey('password_hash');
});

it('revokes a share that belongs to the node', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'revoke-me');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, scope: GrantScope::Item);
    $share = ShareTestHelpers::share($node, token: 'kill-me');

    $this->deleteJson('api/vaulter/nodes/'.$node->getKey().'/shares/'.$share->getKey())
        ->assertNoContent();

    expect($share->refresh()->revoked_at)->not->toBeNull();
});

it('404s revoking a share that belongs to a DIFFERENT node', function (): void {
    $storage = AccessTestHelpers::storage();
    $nodeA = AccessTestHelpers::folder($storage, null, 'A');
    $nodeB = AccessTestHelpers::folder($storage, null, 'B');
    AccessTestHelpers::grant($nodeA, RoleRegistry::OWNER, scope: GrantScope::Item);
    $shareOnB = ShareTestHelpers::share($nodeB, token: 'b-share');

    $this->deleteJson('api/vaulter/nodes/'.$nodeA->getKey().'/shares/'.$shareOnB->getKey())
        ->assertNotFound();

    expect($shareOnB->refresh()->revoked_at)->toBeNull();
});

// ─────────────────────────────────────── public surface ───────────────────────

it('resolves a public share token to node metadata within scope', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'public-doc');
    ShareTestHelpers::share($node, token: 'public-token', permissions: (Permission::View->value | Permission::Download->value));

    $this->getJson('api/vaulter/share/public-token')
        ->assertOk()
        ->assertJsonPath('data.id', expectedOpaqueNodeId($node->getKey()))
        ->assertJsonPath('data.name', 'public-doc');
});

it('never exposes the raw sequential node id publicly, only an opaque projection (F-P1.3-05)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'opaque-doc');
    ShareTestHelpers::share($node, token: 'opaque-token', permissions: (Permission::View->value | Permission::Download->value));

    $response = $this->getJson('api/vaulter/share/opaque-token')->assertOk();

    expect($response->json('data.id'))
        ->not->toBe($node->getKey())
        ->toBe(expectedOpaqueNodeId($node->getKey()));
});

it('404s a public resolve for a wrong/expired token', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'public-doc');
    ShareTestHelpers::share($node, token: 'real-token');

    $this->getJson('api/vaulter/share/fake-token')->assertNotFound();
});

it('401s a password-gated public resolve until the password is supplied', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'pw-doc');
    ShareTestHelpers::share($node, token: 'pw-token', passwordHash: Hash::make('open-sesame'));

    $this->getJson('api/vaulter/share/pw-token')
        ->assertStatus(401)
        ->assertJsonPath('password_required', true);

    $this->getJson('api/vaulter/share/pw-token', ['X-Share-Password' => 'open-sesame'])
        ->assertOk()
        ->assertJsonPath('data.id', expectedOpaqueNodeId($node->getKey()));
});

it('serves a public download within the READ bits, bounds it by max_uses, and never redirects even on a disk that provides a temporary url (F-P1.3-01)', function (): void {
    $drive = Vaulter::drive(DriveTestHelpers::host())->asSystem('test fixture');

    $fileNode = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('public bytes'), 'pub.txt'), new UploadOptions(declaredMimeType: 'text/plain'));

    $share = ShareTestHelpers::share(
        $fileNode->refresh(),
        token: 'dl-token',
        permissions: (Permission::View->value | Permission::Download->value),
        maxUses: 1,
    );

    // The faked local disk DOES provide a temporary url — F-P1.3-01 removed the
    // presign-redirect branch, so the response is always a streamed 200, never a
    // 302, regardless of what the disk can mint.
    $response = $this->get('api/vaulter/share/dl-token/download');
    $response->assertOk();
    expect($response->streamedContent())->toBe('public bytes');

    // The single use was consumed atomically.
    expect($share->refresh()->use_count)->toBe(1);

    // A second hit is over budget — denied (404), and never bumps past max_uses.
    $this->get('api/vaulter/share/dl-token/download')->assertNotFound();
    expect($share->refresh()->use_count)->toBe(1);
});

it('denies a public download when the share lacks the READ bit', function (): void {
    $drive = Vaulter::drive(DriveTestHelpers::host())->asSystem('test fixture');
    $fileNode = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('secret bytes'), 'no.txt'));

    // permissions=0 → no READ/DOWNLOAD bit; the download is denied.
    ShareTestHelpers::share($fileNode->refresh(), token: 'norights-token', permissions: 0);

    $this->get('api/vaulter/share/norights-token/download')->assertNotFound();
});

it('lists a subtree share but 404s a listing on an item share', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'tree');
    AccessTestHelpers::folder($storage, $folder, 'inside');

    ShareTestHelpers::share($folder, token: 'subtree-token', scope: GrantScope::Subtree);
    ShareTestHelpers::share($folder, token: 'item-token', scope: GrantScope::Item);

    $this->getJson('api/vaulter/share/subtree-token/nodes')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'inside');

    $this->getJson('api/vaulter/share/item-token/nodes')->assertNotFound();
});

it('429s a public share resolve past the vaulter-public rate limit (F-P1.8-01)', function (): void {
    config()->set('vaulter.routes.rate_limits.public', 1);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'throttled-doc');
    ShareTestHelpers::share($node, token: 'throttle-token');

    // First request within budget.
    $this->getJson('api/vaulter/share/throttle-token')->assertOk();

    // Second request — same IP, same minute — is over budget.
    $this->getJson('api/vaulter/share/throttle-token')->assertStatus(429);
});
