<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Enums\Access\GrantLevel;
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
    config()->set('vaulter.drivers.permission', 'database');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

// ─────────────────────────────────────── owner surface ────────────────────────

it('lets an owner with the Share ability mint a link and returns the raw token once', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'shareable');
    AccessTestHelpers::grant($node, GrantLevel::Owner, scope: GrantScope::Item); // grants SHARE bit

    $response = $this->postJson('api/vaulter/nodes/'.$node->getKey().'/shares', [
        'permissions' => Permission::Read->value,
    ])->assertCreated();

    // The raw token + URL come back exactly once.
    $token = $response->json('data.token');
    expect($token)->toBeString()->not->toBe('');
    $response->assertJsonPath('data.url', '/share/'.$token);

    // Only the hash is stored.
    $row = NodeShare::query()->firstOrFail();
    expect($row->token_hash)->toBe(hash('sha256', $token));
});

it('403s an owner WITHOUT the Share ability', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'locked');
    // Reader does NOT carry the SHARE bit.
    AccessTestHelpers::grant($node, GrantLevel::Reader, scope: GrantScope::Item);

    $this->postJson('api/vaulter/nodes/'.$node->getKey().'/shares', [
        'permissions' => Permission::Read->value,
    ])->assertStatus(403);
});

it('lists shares WITHOUT ever exposing the raw token or hash', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'listed');
    AccessTestHelpers::grant($node, GrantLevel::Owner, scope: GrantScope::Item);
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
    AccessTestHelpers::grant($node, GrantLevel::Owner, scope: GrantScope::Item);
    $share = ShareTestHelpers::share($node, token: 'kill-me');

    $this->deleteJson('api/vaulter/nodes/'.$node->getKey().'/shares/'.$share->getKey())
        ->assertNoContent();

    expect($share->refresh()->revoked_at)->not->toBeNull();
});

it('404s revoking a share that belongs to a DIFFERENT node', function (): void {
    $storage = AccessTestHelpers::storage();
    $nodeA = AccessTestHelpers::folder($storage, null, 'A');
    $nodeB = AccessTestHelpers::folder($storage, null, 'B');
    AccessTestHelpers::grant($nodeA, GrantLevel::Owner, scope: GrantScope::Item);
    $shareOnB = ShareTestHelpers::share($nodeB, token: 'b-share');

    $this->deleteJson('api/vaulter/nodes/'.$nodeA->getKey().'/shares/'.$shareOnB->getKey())
        ->assertNotFound();

    expect($shareOnB->refresh()->revoked_at)->toBeNull();
});

// ─────────────────────────────────────── public surface ───────────────────────

it('resolves a public share token to node metadata within scope', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'public-doc');
    ShareTestHelpers::share($node, token: 'public-token', permissions: Permission::Read->value);

    $this->getJson('api/vaulter/share/public-token')
        ->assertOk()
        ->assertJsonPath('data.id', $node->getKey())
        ->assertJsonPath('data.name', 'public-doc');
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
        ->assertJsonPath('data.id', $node->getKey());
});

it('serves a public download within the READ bits and bounds it by max_uses', function (): void {
    $drive = Vaulter::for(DriveTestHelpers::host());

    $fileNode = $drive->upload(DriveTestHelpers::streamFor('public bytes'), originalName: 'pub.txt', mimeType: 'text/plain');

    $share = ShareTestHelpers::share(
        $fileNode->refresh(),
        token: 'dl-token',
        permissions: Permission::Read->value,
        maxUses: 1,
    );

    // A faked disk may mint a signed URL (302 redirect) or stream inline (200) —
    // both are valid READ-bit downloads; the point is the request is served.
    $response = $this->get('api/vaulter/share/dl-token/download');
    expect($response->status())->toBeIn([200, 302]);

    // The single use was consumed atomically.
    expect($share->refresh()->use_count)->toBe(1);

    // A second hit is over budget — denied (404), and never bumps past max_uses.
    $this->get('api/vaulter/share/dl-token/download')->assertNotFound();
    expect($share->refresh()->use_count)->toBe(1);
});

it('denies a public download when the share lacks the READ bit', function (): void {
    $drive = Vaulter::for(DriveTestHelpers::host());
    $fileNode = $drive->upload(DriveTestHelpers::streamFor('secret bytes'), originalName: 'no.txt');

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
