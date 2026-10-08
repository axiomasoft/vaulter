<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\Types\AcmeKanbanV2Type;
use Vaulter\Documents\Tests\Types\TypesTestCase;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * PLAN2.P4.3: the lenient read must not become a lossy save. Content that reads only
 * partly under its type — nodes of an editor extension the host switched off, or a
 * schema newer than the installed type — is shown read-only and update() refuses it
 * with document_content_degraded, so the dropped parts survive until the host fixes
 * the configuration. An older schema is upgraded on read without rewriting history.
 */

/** @return array<string, mixed> */
function widgetDocument(): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'before']]],
        ['type' => 'acmeWidget'],
    ]];
}

it('reads content of a switched-off editor extension degraded and refuses to save over it', function (): void {
    config()->set('vaulter-documents.tiptap.extra_nodes', ['acmeWidget']);
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);
    $node = $documents->create('Spec', content: widgetDocument());

    // The host switches the extension off.
    config()->set('vaulter-documents.tiptap.extra_nodes', []);

    $read = $documents->content($node->id);
    expect($read->typeAvailable)->toBeTrue()
        ->and($read->editable)->toBeFalse()
        ->and(array_column($read->content->toArray()['content'], 'type'))->toBe(['paragraph']);

    expect(fn () => $documents->update($node->id, ['type' => 'doc', 'content' => []]))
        ->toThrow(DocumentContentDegradedException::class);

    // Nothing was lost.
    expect(Document::query()->where('node_id', $node->id)->firstOrFail()->content)->toBe(widgetDocument());

    // Switched back on, the document is editable again.
    config()->set('vaulter-documents.tiptap.extra_nodes', ['acmeWidget']);
    expect($documents->content($node->id)->editable)->toBeTrue()
        ->and($documents->update($node->id, ['type' => 'doc', 'content' => []])->editable)->toBeTrue();
});

it('answers 409 document_content_degraded to an HTTP autosave', function (): void {
    config()->set('vaulter-documents.tiptap.extra_nodes', ['acmeWidget']);
    $node = Documents::in(DocumentTestHelpers::drive())->create('Spec', content: widgetDocument());
    config()->set('vaulter-documents.tiptap.extra_nodes', []);

    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    $this->patchJson('api/vaulter/nodes/'.$node->id.'/content', ['content' => ['type' => 'doc', 'content' => []]])
        ->assertStatus(409)
        ->assertJsonPath('code', 'document_content_degraded');
});

it('keeps a document written in a newer schema read-only', function (): void {
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);
    $node = $documents->create('Board', type: 'acme/kanban', content: ['columns' => []]);
    app(VaulterDatabase::class)->write(fn () => Document::query()->where('node_id', $node->id)->update(['schema_version' => 2]));

    $read = $documents->content($node->id);
    expect($read->typeAvailable)->toBeTrue()
        ->and($read->schemaVersion)->toBe(2)
        ->and($read->editable)->toBeFalse();

    expect(fn () => $documents->update($node->id, ['columns' => []]))->toThrow(DocumentContentDegradedException::class);
});

it('upgrades an older schema on read without rewriting the stored content', function (): void {
    $handle = DocumentTestHelpers::drive();
    $node = Documents::in($handle)->create('Board', type: 'acme/kanban', content: ['columns' => [['title' => 'Todo', 'cards' => ['a']]]]);

    TypesTestCase::installTypes([RichTextDocumentType::class, MarkdownDocumentType::class, AcmeKanbanV2Type::class]);
    $documents = Documents::in($handle);

    $read = $documents->content($node->id);
    expect($read->content->toArray())->toBe(['columns' => [['title' => 'Todo', 'items' => ['a']]]])
        ->and($read->schemaVersion)->toBe(1)
        ->and($read->editable)->toBeTrue();

    $stored = Document::query()->where('node_id', $node->id)->firstOrFail();
    expect($stored->content)->toBe(['columns' => [['title' => 'Todo', 'cards' => ['a']]]])
        ->and($stored->schema_version)->toBe(1);

    $saved = $documents->update($node->id, $read->content->toArray());
    expect($saved->schemaVersion)->toBe(2)
        ->and(Document::query()->where('node_id', $node->id)->firstOrFail()->schema_version)->toBe(2);
});
