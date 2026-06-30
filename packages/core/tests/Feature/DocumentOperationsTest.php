<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Events\Documents\DocumentMoved;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Documents\Document;
use Vaulter\Models\Documents\DocumentFolder;
use Vaulter\Tests\Fixtures\AllowResolver;
use Vaulter\Tests\Fixtures\NoCreateResolver;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 41]));
    Vaulter::resolvePermissionsWith(new AllowResolver);
});

it('moves a document into a folder of the same workspace', function (): void {
    Event::fake([DocumentMoved::class]);
    $document = Document::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1]);
    $folder = DocumentFolder::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1]);

    $this->patchJson(sprintf('api/vaulter/documents/%s/move', $document->id), ['folder_id' => $folder->id])
        ->assertOk()
        ->assertJsonPath('data.folder_id', $folder->id);

    Event::assertDispatched(DocumentMoved::class);
});

it('moves a document back to the root with a null folder', function (): void {
    $folder = DocumentFolder::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1]);
    $document = Document::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1, 'folder_id' => $folder->id]);

    $this->patchJson(sprintf('api/vaulter/documents/%s/move', $document->id), ['folder_id' => null])
        ->assertOk()
        ->assertJsonPath('data.folder_id', null);
});

it('rejects moving a document into a folder from another workspace', function (): void {
    $document = Document::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1]);
    $foreign = DocumentFolder::factory()->create(['workspace_type' => 'w', 'workspace_id' => 2]);

    $this->patchJson(sprintf('api/vaulter/documents/%s/move', $document->id), ['folder_id' => $foreign->id])
        ->assertStatus(422);
});

it('duplicates a document', function (): void {
    $document = Document::factory()->create(['title' => 'Spec']);

    $this->postJson(sprintf('api/vaulter/documents/%s/duplicate', $document->id))
        ->assertCreated()
        ->assertJsonPath('data.title', 'Copy of Spec');

    expect(Document::query()->where('title', 'Copy of Spec')->count())->toBe(1);
});

it('forbids duplicating without the create ability', function (): void {
    Vaulter::resolvePermissionsWith(new NoCreateResolver);
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/vaulter/documents/%s/duplicate', $document->id))->assertForbidden();
});
