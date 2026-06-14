<?php

declare(strict_types=1);

use DocVault\Events\DocumentPurged;
use DocVault\Facades\DocVault;
use DocVault\Models\Document;
use DocVault\Tests\Fixtures\AllowResolver;
use DocVault\Tests\Fixtures\DenyResolver;
use DocVault\Tests\Fixtures\TestUser;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 31]));
    DocVault::resolvePermissionsWith(new AllowResolver);
});

it('searches documents by title and content', function (): void {
    Document::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1, 'title' => 'Roadmap 2026']);
    Document::factory()->create([
        'workspace_type' => 'w', 'workspace_id' => 1, 'title' => 'Other',
        'content' => ['type' => 'doc', 'content' => [['type' => 'text', 'text' => 'mentions roadmap inside']]],
    ]);
    Document::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1, 'title' => 'Unrelated']);

    $this->getJson('api/docvault/documents?workspace_type=w&workspace_id=1&q=roadmap')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('lists only trashed documents in the workspace', function (): void {
    $kept = Document::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1]);
    $trashed = Document::factory()->create(['workspace_type' => 'w', 'workspace_id' => 1]);
    $trashed->delete();

    $this->getJson('api/docvault/documents/trash?workspace_type=w&workspace_id=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $trashed->id);

    expect($kept->id)->not->toBe($trashed->id);
});

it('permanently force-deletes a trashed document and dispatches DocumentPurged', function (): void {
    Event::fake([DocumentPurged::class]);
    $document = Document::factory()->create();
    $document->delete();

    $this->deleteJson(sprintf('api/docvault/documents/%s/force', $document->id))->assertNoContent();

    $this->assertDatabaseMissing('dv_documents', ['id' => $document->id]);
    Event::assertDispatched(DocumentPurged::class);
});

it('forbids force-delete when the resolver denies manage', function (): void {
    $document = Document::factory()->create();
    $document->delete();

    DocVault::resolvePermissionsWith(new DenyResolver);

    $this->deleteJson(sprintf('api/docvault/documents/%s/force', $document->id))->assertForbidden();
});
