<?php

declare(strict_types=1);

use DocVault\Actions\CreateDocumentAction;
use DocVault\Actions\CreateVersionAction;
use DocVault\Data\CreateDocumentData;
use DocVault\Data\CreateVersionData;
use DocVault\Enums\DocumentType;
use DocVault\Events\DocumentCreated;
use DocVault\Events\VersionCreated;
use DocVault\Models\Document;
use DocVault\ValueObjects\TipTapContent;
use Illuminate\Support\Facades\Event;

it('creates a document and dispatches DocumentCreated', function (): void {
    Event::fake([DocumentCreated::class]);

    $document = app(CreateDocumentAction::class)->execute(new CreateDocumentData(
        workspaceType: 'App\\Project',
        workspaceId: 1,
        type: DocumentType::RichText,
        title: 'Specification',
        content: TipTapContent::empty(),
        actorId: 42,
    ));

    expect($document->title)->toBe('Specification')
        ->and($document->type)->toBe(DocumentType::RichText);

    $this->assertDatabaseHas('dv_documents', ['title' => 'Specification']);
    Event::assertDispatched(DocumentCreated::class);
});

it('prunes versions beyond the configured maximum', function (): void {
    config(['docvault.max_versions' => 2]);
    Event::fake([VersionCreated::class]);

    $document = Document::factory()->create();
    $action = app(CreateVersionAction::class);

    foreach (range(1, 4) as $index) {
        $action->execute($document, new CreateVersionData(snapshotLabel: 'v'.$index));
    }

    expect($document->versions()->count())->toBe(2);
    Event::assertDispatched(VersionCreated::class, 4);
});
