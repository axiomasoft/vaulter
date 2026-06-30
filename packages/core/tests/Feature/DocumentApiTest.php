<?php

declare(strict_types=1);

use Vaulter\Facades\Vaulter;
use Vaulter\Models\Documents\Document;
use Vaulter\Tests\Fixtures\AllowResolver;
use Vaulter\Tests\Fixtures\DenyResolver;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

it('creates a document and returns abilities', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);

    $this->postJson('api/vaulter/documents', [
        'workspace_type' => 'App\\Project',
        'workspace_id' => 1,
        'type' => 'richtext',
        'title' => 'Spec',
        'content' => ['type' => 'doc', 'content' => []],
    ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Spec')
        ->assertJsonPath('data.abilities.edit', true);

    $this->assertDatabaseHas('v_documents', ['title' => 'Spec']);
});

it('lists documents scoped to a workspace', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);
    Document::factory()->count(2)->create(['workspace_type' => 'App\\Project', 'workspace_id' => 1]);
    Document::factory()->create(['workspace_type' => 'App\\Project', 'workspace_id' => 2]);

    $this->getJson('api/vaulter/documents?workspace_type=App\\Project&workspace_id=1')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('forbids updating a document when the resolver denies', function (): void {
    Vaulter::resolvePermissionsWith(new DenyResolver);
    $document = Document::factory()->create();

    $this->patchJson('api/vaulter/documents/'.$document->id, ['title' => 'Nope'])
        ->assertForbidden();
});

it('creates a version and restores it', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create(['content' => ['type' => 'doc', 'content' => ['v1']]]);

    $this->postJson(sprintf('api/vaulter/documents/%s/versions', $document->id), ['snapshot_label' => 'v1'])
        ->assertCreated();

    $this->patchJson('api/vaulter/documents/'.$document->id, [
        'content' => ['type' => 'doc', 'content' => ['v2']],
    ])->assertOk();

    $versionId = $document->versions()->firstOrFail()->getKey();

    $this->postJson(sprintf('api/vaulter/documents/%s/versions/%s/restore', $document->id, $versionId))
        ->assertOk()
        ->assertJsonPath('data.content.content.0', 'v1');
});

it('grants and revokes a permission', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();

    $response = $this->postJson(sprintf('api/vaulter/documents/%s/permissions', $document->id), [
        'subject_type' => 'user',
        'subject_id' => 7,
        'permission' => 'edit',
    ])->assertCreated();

    $this->assertDatabaseHas('v_document_permissions', ['subject_id' => 7, 'permission' => 'edit']);

    $permissionId = $response->json('data.id');

    $this->deleteJson(sprintf('api/vaulter/documents/%s/permissions/%s', $document->id, $permissionId))
        ->assertNoContent();

    $this->assertDatabaseMissing('v_document_permissions', ['subject_id' => 7]);
});

it('issues a collaboration token', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);
    // The default transport is now hocuspocus; this legacy document endpoint is pinned
    // to the reverb driver explicitly (it authenticates via the channel, no JWT secret).
    config()->set('vaulter.collaboration.driver', 'reverb');
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/vaulter/documents/%s/collab/token', $document->id))
        ->assertOk()
        ->assertJsonPath('driver', 'reverb');
});

it('soft deletes and restores a document', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();

    $this->deleteJson('api/vaulter/documents/'.$document->id)->assertNoContent();
    $this->assertSoftDeleted('v_documents', ['id' => $document->id]);

    $this->postJson(sprintf('api/vaulter/documents/%s/restore', $document->id))->assertOk();
    $this->assertDatabaseHas('v_documents', ['id' => $document->id, 'deleted_at' => null]);
});

it('creates folders and lists them', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);

    $this->postJson('api/vaulter/folders', [
        'workspace_type' => 'App\\Project',
        'workspace_id' => 1,
        'name' => 'Contracts',
    ])->assertCreated()->assertJsonPath('data.name', 'Contracts');

    $this->getJson('api/vaulter/folders?workspace_type=App\\Project&workspace_id=1')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
