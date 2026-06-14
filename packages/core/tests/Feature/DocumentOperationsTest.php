<?php

declare(strict_types=1);

use DocVault\Events\DocumentMoved;
use DocVault\Facades\DocVault;
use DocVault\Models\Document;
use DocVault\Models\DocumentFolder;
use DocVault\Tests\Fixtures\AllowResolver;
use DocVault\Tests\Fixtures\NoCreateResolver;
use DocVault\Tests\Fixtures\TestUser;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 41]));
    DocVault::resolvePermissionsWith(new AllowResolver);
});

it('moves a document into a folder of the same workspace', function (): void {
    Event::fake([DocumentMoved::class]);
    $document = Document::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1]);
    $folder = DocumentFolder::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1]);

    $this->patchJson(sprintf('api/docvault/documents/%s/move', $document->id), ['folder_id' => $folder->id])
        ->assertOk()
        ->assertJsonPath('data.folder_id', $folder->id);

    Event::assertDispatched(DocumentMoved::class);
});

it('moves a document back to the root with a null folder', function (): void {
    $folder = DocumentFolder::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1]);
    $document = Document::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1, 'folder_id' => $folder->id]);

    $this->patchJson(sprintf('api/docvault/documents/%s/move', $document->id), ['folder_id' => null])
        ->assertOk()
        ->assertJsonPath('data.folder_id', null);
});

it('rejects moving a document into a folder from another workspace', function (): void {
    $document = Document::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1]);
    $foreign = DocumentFolder::factory()->create(['workspace_type' => 'w', 'workspace_id' => 2]);

    $this->patchJson(sprintf('api/docvault/documents/%s/move', $document->id), ['folder_id' => $foreign->id])
        ->assertStatus(422);
});

it('duplicates a document', function (): void {
    $document = Document::factory()->create(['title' => 'Spec']);

    $this->postJson(sprintf('api/docvault/documents/%s/duplicate', $document->id))
        ->assertCreated()
        ->assertJsonPath('data.title', 'Copy of Spec');

    expect(Document::query()->where('title', 'Copy of Spec')->count())->toBe(1);
});

it('forbids duplicating without the create ability', function (): void {
    DocVault::resolvePermissionsWith(new NoCreateResolver);
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/docvault/documents/%s/duplicate', $document->id))->assertForbidden();
});
