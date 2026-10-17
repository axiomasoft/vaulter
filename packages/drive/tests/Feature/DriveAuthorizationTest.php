<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Fixtures\Access\ProfileCreateGate;
use Vaulter\Facades\Vaulter;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * The Drive REST surface UNDER the ACL (M2a / H.2): hidden nodes return 404,
 * an authorised one passes, the per-node grant API grants/revokes, and the node
 * "abilities" object mirrors the resolver. Runs on the standalone database driver so
 * a grant is the only thing that opens a door.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter.access.permission_driver', 'database');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function authStorage(): Drive
{
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

it('hides an unauthorised show with 404', function (): void {
    $storage = authStorage();
    $folder = AccessTestHelpers::folder($storage, null, 'secret');

    $this->getJson('api/vaulter/v1/nodes/'.$folder->getKey())
        ->assertStatus(404);
});

it('allows an authorised show when the actor holds a grant', function (): void {
    $storage = authStorage();
    $folder = AccessTestHelpers::folder($storage, null, 'visible');

    // The acting user (id 1) is granted Reader on the node.
    AccessTestHelpers::grant($folder, RoleRegistry::READER, scope: GrantScope::Item);

    $this->getJson('api/vaulter/v1/nodes/'.$folder->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', (string) $folder->getKey());
});

it('hides an unauthorised folder-create destination with 404', function (): void {
    $storage = authStorage();

    // The root is hidden without a View grant.
    $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/folders', ['name' => 'Nope'])
        ->assertStatus(404);
});

it('allows a folder create when the actor may create at the destination', function (): void {
    $storage = authStorage();
    $root = $storage->rootNode()->first();

    // Writer (CREATE bit) on the root, inherited subtree.
    AccessTestHelpers::grant($root, RoleRegistry::WRITER, scope: GrantScope::Subtree);

    $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/folders', ['name' => 'Allowed'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Allowed');
});

it('fills the node abilities object from the resolver', function (): void {
    $storage = authStorage();
    $folder = AccessTestHelpers::folder($storage, null, 'flags');

    // Reader only: view true, edit/trash false.
    AccessTestHelpers::grant($folder, RoleRegistry::READER, scope: GrantScope::Item);

    $this->getJson('api/vaulter/v1/nodes/'.$folder->getKey())
        ->assertOk()
        ->assertJsonPath('data.abilities.view', true)
        ->assertJsonPath('data.abilities.edit', false)
        ->assertJsonPath('data.abilities.trash', false)
        ->assertJsonPath('data.abilities.manage', false);
});

it('grants then revokes access through the grant API', function (): void {
    $storage = authStorage();
    $folder = AccessTestHelpers::folder($storage, null, 'managed');

    // The acting user needs SHARE on the node to manage its grants — give Owner.
    AccessTestHelpers::grant($folder, RoleRegistry::OWNER, scope: GrantScope::Item);

    $subjectType = (new TestUser)->getMorphClass();

    // Grant a second subject (id 2) Reader.
    $created = $this->postJson('api/vaulter/v1/nodes/'.$folder->getKey().'/grants', [
        'subject' => ['type' => $subjectType, 'id' => 2],
        'role' => 'reader',
    ])->assertCreated()
        ->assertJsonPath('data.role', 'reader');

    expect(NodeGrant::query()->where('node_id', $folder->id)->where('subject_id', 2)->exists())->toBeTrue();

    // List shows both grants (owner self + reader subject 2).
    $this->getJson('api/vaulter/v1/nodes/'.$folder->getKey().'/grants')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Revoke subject 2's grant by its id.
    $this->deleteJson('api/vaulter/v1/nodes/'.$folder->getKey().'/grants/'.$created->json('data.id'))->assertNoContent();

    expect(NodeGrant::query()->where('node_id', $folder->id)->where('subject_id', 2)->exists())->toBeFalse();
});

it('rejects a grant with an unregistered subject_type (422)', function (): void {
    $storage = authStorage();
    $folder = AccessTestHelpers::folder($storage, null, 'bogus-subject');

    // The actor may share the node — so the 422 comes from validation, not the guard.
    AccessTestHelpers::grant($folder, RoleRegistry::OWNER, scope: GrantScope::Item);

    // A made-up / typo'd subject_type that maps to no host model must be rejected
    // before it can ever land in v_node_grants (§2.6 / H.2 — alias-only subjects).
    $this->postJson('api/vaulter/v1/nodes/'.$folder->getKey().'/grants', [
        'subject' => ['type' => 'totally-not-a-model', 'id' => 2],
        'role' => 'reader',
    ])->assertStatus(422)
        ->assertJsonValidationErrorFor('subject.type');

    expect(NodeGrant::query()->where('node_id', $folder->id)->where('subject_id', 2)->exists())->toBeFalse();
});

it('refuses to revoke a grant of another node through this one (404)', function (): void {
    $storage = authStorage();
    $folder = AccessTestHelpers::folder($storage, null, 'bogus-revoke');
    $other = AccessTestHelpers::folder($storage, null, 'other');

    AccessTestHelpers::grant($folder, RoleRegistry::OWNER, scope: GrantScope::Item);
    $foreign = AccessTestHelpers::grant($other, RoleRegistry::READER, scope: GrantScope::Item, subjectId: 2);

    $this->deleteJson('api/vaulter/v1/nodes/'.$folder->getKey().'/grants/'.$foreign->getKey())->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});

it('blocks the grant API for an actor without SHARE', function (): void {
    $storage = authStorage();
    $folder = AccessTestHelpers::folder($storage, null, 'noshare');

    // Reader only — no SHARE bit ⇒ cannot manage grants.
    AccessTestHelpers::grant($folder, RoleRegistry::READER, scope: GrantScope::Item);

    $this->postJson('api/vaulter/v1/nodes/'.$folder->getKey().'/grants', [
        'subject' => ['type' => (new TestUser)->getMorphClass(), 'id' => 2],
        'role' => 'reader',
    ])->assertStatus(403);
});

it('denies a download without a grant and allows it with one', function (): void {
    $storage = authStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();

    // Grant create on root so the actor can upload, then a file appears.
    $root = $storage->rootNode()->first();
    AccessTestHelpers::grant($root, RoleRegistry::WRITER, scope: GrantScope::Subtree);

    $nodeId = $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('d.txt', 'bytes'),
    ])->assertCreated()->json('data.id');

    // A second actor (id 99) with no grant cannot download.
    $this->actingAs((new TestUser)->forceFill(['id' => 99]));
    $this->get('api/vaulter/v1/files/'.$nodeId.'/download')->assertStatus(404);

    // The uploader (Writer ⇒ READ bit) can — streamed through PHP (P1.2 /
    // F-P1.3-01 removed the presign-redirect branch: even a disk that can mint a
    // signed URL now always streams a 200, never a 302).
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    $this->get('api/vaulter/v1/files/'.$nodeId.'/download')->assertOk();
});

it('gates folder creation through the profile driver', function (bool $allow): void {
    $drive = authStorage();
    ProfileCreateGate::install($allow);
    $this->postJson('api/vaulter/v1/drives/'.$drive->id.'/folders', ['name' => 'profile-folder'])
        ->assertStatus($allow ? 201 : 403);
})->with([true, false]);
