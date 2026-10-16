<?php

declare(strict_types=1);

use Vaulter\Contracts\Handlers\NodePresenter;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\UnknownDocumentTypeException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\Types\AcmeKanbanType;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Documents\Tests\Types\TypesTestCase;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Documents\Types\SpreadsheetDocumentType;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * PLAN2.P4.3 / V10: a third-party document type is installed, a document of it is
 * created, the extension is removed and installed again. Without the type the
 * document stays in the tree, reads as opaque JSON and can be copied, trashed and
 * purged; editing and exporting answer document_type_unavailable. Back with the
 * type, everything works and nothing was lost.
 */
/** @return array<string, mixed> */
function kanbanBoard(): array
{
    return ['columns' => [['title' => 'Todo', 'cards' => ['write spec']]]];
}

/** @return list<class-string> */
function typesWithoutKanban(): array
{
    return [RichTextDocumentType::class, MarkdownDocumentType::class, SpreadsheetDocumentType::class];
}

it('keeps a document of a removed type readable, read-only and movable, then works again once reinstalled', function (): void {
    $handle = DocumentTestHelpers::drive();
    $node = Documents::in($handle)->create('Board', type: 'acme/kanban', content: kanbanBoard());
    $stored = DocumentTestHelpers::document(DriveTestHelpers::model($node));

    expect($node->document?->type)->toBe('acme/kanban')
        ->and($node->document?->typeAvailable)->toBeTrue()
        // JSON object key order is not preserved by PostgreSQL/MySQL.
        ->and($stored->content)->toEqual(kanbanBoard());

    // The extension is removed.
    TypesTestCase::installTypes(typesWithoutKanban());
    $documents = Documents::in($handle);

    $read = $documents->content($node->id);
    expect($read->typeAvailable)->toBeFalse()
        ->and($read->editable)->toBeFalse()
        ->and($read->type)->toBe('acme/kanban')
        ->and($read->content)->toBeInstanceOf(OpaqueContent::class)
        ->and($read->content->toArray())->toEqual(kanbanBoard())
        ->and($handle->nodes()->find($node->id)->document?->typeAvailable)->toBeFalse();

    expect(fn () => $documents->update($node->id, ['columns' => []]))->toThrow(DocumentTypeUnavailableException::class)
        ->and(fn () => $documents->export($node->id, 'html'))->toThrow(DocumentTypeUnavailableException::class)
        ->and(fn () => $documents->snapshot($node->id, 'v2'))->toThrow(DocumentTypeUnavailableException::class)
        ->and(fn () => $documents->restore($node->id, (string) $read->headRevisionId))->toThrow(DocumentTypeUnavailableException::class)
        ->and(fn () => $documents->create('Another', type: 'acme/kanban'))->toThrow(UnknownDocumentTypeException::class);

    // History, copy and trash still work.
    expect($documents->revisions($node->id)->items)->toHaveCount(1);
    $copy = $handle->nodes()->copy($node->id, options: new CopyOptions(name: 'Board copy'));
    expect(Document::query()->where('node_id', $copy->id)->firstOrFail()->content)->toEqual(kanbanBoard());
    $handle->nodes()->trash($copy->id);
    $handle->trash()->purge($copy->id);
    expect(Document::query()->where('node_id', $copy->id)->exists())->toBeFalse();

    // The extension is installed again: the same document is fully usable.
    TypesTestCase::installTypes([...typesWithoutKanban(), AcmeKanbanType::class]);
    $documents = Documents::in($handle);

    $back = $documents->content($node->id);
    expect($back->typeAvailable)->toBeTrue()
        ->and($back->editable)->toBeTrue()
        ->and($back->content->toArray())->toEqual(kanbanBoard());

    $updated = $documents->update($node->id, ['columns' => []], expectedStateVersion: $back->stateVersion);
    expect($updated->content->toArray())->toBe(['columns' => []])
        ->and($updated->stateVersion)->toBe($back->stateVersion + 1);
});

it('answers 409 document_type_unavailable over HTTP and still serves the JSON content', function (): void {
    $handle = DocumentTestHelpers::drive();
    $node = Documents::in($handle)->create('Board', type: 'acme/kanban', content: kanbanBoard());
    TypesTestCase::installTypes(typesWithoutKanban());

    $this->actingAs((new TestUser)->forceFill(['id' => 1]));

    $this->getJson('api/vaulter/v1/documents/'.$node->id.'/content')
        ->assertOk()
        ->assertJsonPath('data.content', static fn (array $content): bool => $content == kanbanBoard());

    $this->putJson('api/vaulter/v1/documents/'.$node->id.'/content', ['content' => ['columns' => []]], Docs::ifMatch($node->id))
        ->assertStatus(409)
        ->assertJsonPath('code', 'document_type_unavailable');
});

it('presents a document of a removed type with the unsupported viewer', function (): void {
    $handle = DocumentTestHelpers::drive();
    $node = Documents::in($handle)->create('Board', type: 'acme/kanban', content: kanbanBoard());
    TypesTestCase::installTypes(typesWithoutKanban());

    $presentation = app(NodePresenter::class)->present($handle->nodes()->find($node->id))->toArray();

    expect($presentation['id'])->toBeNull()
        ->and($presentation['document_type'])->toBe('acme/kanban')
        ->and($presentation['type_available'])->toBeFalse()
        ->and($presentation['ui']['key'])->toBe('vaulter/unsupported')
        ->and($presentation['capabilities']['editable'])->toBeFalse()
        ->and($presentation['capabilities']['collaborative'])->toBeFalse();
});
