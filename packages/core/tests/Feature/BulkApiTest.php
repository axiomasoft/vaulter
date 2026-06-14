<?php

declare(strict_types=1);

use DocVault\Facades\DocVault;
use DocVault\Models\Document;
use DocVault\Models\DocumentFolder;
use DocVault\Tests\Fixtures\AllowResolver;
use DocVault\Tests\Fixtures\DenyResolver;
use DocVault\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 51]));
    DocVault::resolvePermissionsWith(new AllowResolver);
});

it('bulk-deletes documents', function (): void {
    $a = Document::factory()->create();
    $b = Document::factory()->create();

    $this->postJson('api/docvault/documents/bulk', ['action' => 'delete', 'ids' => [$a->id, $b->id]])
        ->assertOk()
        ->assertJsonPath('data.action', 'delete')
        ->assertJsonCount(2, 'data.processed');

    $this->assertSoftDeleted('dv_documents', ['id' => $a->id]);
    $this->assertSoftDeleted('dv_documents', ['id' => $b->id]);
});

it('bulk-moves documents into a folder', function (): void {
    $folder = DocumentFolder::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1]);
    $a = Document::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1]);
    $b = Document::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1]);

    $this->postJson('api/docvault/documents/bulk', [
        'action' => 'move',
        'ids' => [$a->id, $b->id],
        'folder_id' => $folder->id,
    ])->assertOk()->assertJsonCount(2, 'data.processed');

    $this->assertDatabaseHas('dv_documents', ['id' => $a->id, 'folder_id' => $folder->id]);
});

it('bulk-restores trashed documents', function (): void {
    $a = Document::factory()->create();
    $a->delete();

    $this->postJson('api/docvault/documents/bulk', ['action' => 'restore', 'ids' => [$a->id]])
        ->assertOk()
        ->assertJsonCount(1, 'data.processed');

    $this->assertDatabaseHas('dv_documents', ['id' => $a->id, 'deleted_at' => null]);
});

it('skips documents the resolver denies and unknown ids', function (): void {
    $document = Document::factory()->create();
    DocVault::resolvePermissionsWith(new DenyResolver);

    $this->postJson('api/docvault/documents/bulk', ['action' => 'delete', 'ids' => [$document->id, 999999]])
        ->assertOk()
        ->assertJsonCount(0, 'data.processed')
        ->assertJsonCount(2, 'data.skipped');

    $this->assertDatabaseHas('dv_documents', ['id' => $document->id, 'deleted_at' => null]);
});

it('rejects an unknown bulk action', function (): void {
    $document = Document::factory()->create();

    $this->postJson('api/docvault/documents/bulk', ['action' => 'nope', 'ids' => [$document->id]])
        ->assertStatus(422);
});
