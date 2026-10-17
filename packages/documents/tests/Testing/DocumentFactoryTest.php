<?php

declare(strict_types=1);

use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/**
 * The factory goes through DocumentOperations, so a fixture document has its node, its
 * type's codec and its first checkpoint like one a host created.
 */
it('creates a rich text document under a drive', function (): void {
    $drive = Drive::factory()->for(DriveTestHelpers::host())->create();

    $document = Document::factory()->richText('Hello')->in($drive)->create();

    expect($document)->toBeInstanceOf(Document::class)
        ->and($document->exists)->toBeTrue()
        ->and($document->type)->toBe(RichTextDocumentType::KEY)
        ->and($document->revision_count)->toBe(1)
        ->and($document->content['content'][0]['content'][0]['text'])->toBe('Hello')
        ->and($document->node->kind)->toBe(NodeKind::Document);
});

it('creates a document of another type with that type default content', function (): void {
    $drive = Drive::factory()->for(DriveTestHelpers::host())->create();

    $document = Document::factory()->ofType('acme/kanban')->named('Board')->in($drive)->create();

    expect($document->type)->toBe('acme/kanban')
        ->and($document->node->name)->toBe('Board')
        ->and($document->content)->toBe(['columns' => []]);
});

it('creates a document inside a folder', function (): void {
    $drive = Drive::factory()->for(DriveTestHelpers::host())->create();
    $folder = Node::factory()->folder()->in($drive)->create();

    $document = Document::factory()->in($folder)->create();

    expect($document->node->parent_id)->toBe($folder->getKey());
});
