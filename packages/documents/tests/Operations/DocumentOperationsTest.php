<?php

declare(strict_types=1);

use Vaulter\Data\Page;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Documents\Exceptions\UnknownDocumentTypeException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\ValueObjects\DocumentExport;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\CreateFolderOptions;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\OnNameConflict;
use Vaulter\Drive\Exceptions\Nodes\InvalidNodeNameException;
use Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/**
 * PLAN2.P4.8: DocumentOperations returns snapshots, keeps the content validator
 * (state version) apart from the node sequence, and resolves revisions only inside
 * the addressed document.
 */

/** @return array{DriveHandle, DocumentOperations, NodeSnapshot} */
function documentsFixture(string $text = 'first'): array
{
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);

    return [$handle, $documents, $documents->create('Spec', content: DocumentTestHelpers::content($text)->toArray())];
}

function documentText(DocumentSnapshot $document): string
{
    $content = $document->content;
    expect($content)->toBeInstanceOf(TipTapContent::class);

    return $content->toArray()['content'][0]['content'][0]['text'];
}

it('creates a document and returns a node snapshot', function (): void {
    [, , $node] = documentsFixture();

    expect($node)->toBeInstanceOf(NodeSnapshot::class)
        ->and($node->kind)->toBe(NodeKind::Document)
        ->and($node->name)->toBe('Spec')
        ->and($node->document?->type)->toBe('vaulter/rich-text')
        ->and($node->document?->stateVersion)->toBe(1);
});

it('reads the working state as a snapshot with its validators', function (): void {
    [, $documents, $node] = documentsFixture('hello');

    $document = $documents->content($node->id);

    expect($document)->toBeInstanceOf(DocumentSnapshot::class)
        ->and($document->nodeId)->toBe($node->id)
        ->and($document->type)->toBe('vaulter/rich-text')
        ->and($document->stateVersion)->toBe(1)
        ->and($document->epoch)->toBe(0)
        ->and($document->editable)->toBeTrue()
        ->and($document->dirty)->toBeFalse()
        ->and($document->revision)->toBeInstanceOf(DocumentRevisionSnapshot::class)
        ->and($document->revision?->isHead)->toBeTrue()
        ->and($document->headRevisionId)->toBe($document->revision?->id)
        ->and(documentText($document))->toBe('hello');
});

it('updates the working state under its own validator, leaving the node sequence alone', function (): void {
    [$handle, $documents, $node] = documentsFixture();
    $sequence = DriveTestHelpers::model($node->id)->sequence;

    $updated = $documents->update($node->id, DocumentTestHelpers::content('second')->toArray(), expectedStateVersion: 1);

    expect($updated->stateVersion)->toBe(2)
        ->and(documentText($updated))->toBe('second')
        ->and(DriveTestHelpers::model($node->id)->sequence)->toBe($sequence)
        ->and(fn () => $documents->update($node->id, DocumentTestHelpers::content('lost')->toArray(), expectedStateVersion: 1))
        ->toThrow(StaleDocumentStateException::class)
        ->and(documentText($documents->content($node->id)))->toBe('second');

    // A rename moves the node validator, not the content one.
    $handle->nodes()->rename($node->id, 'Renamed');
    expect($documents->update($node->id, DocumentTestHelpers::content('third')->toArray(), expectedStateVersion: 2)->stateVersion)->toBe(3);
});

it('snapshots a named head revision and lists revisions newest first in pages', function (): void {
    [, $documents, $node] = documentsFixture();
    $documents->update($node->id, DocumentTestHelpers::content('second')->toArray());

    $named = $documents->snapshot($node->id, 'Release', keepForever: true);
    $first = $documents->revisions($node->id, new ListOptions(perPage: 1));
    $rest = $documents->revisions($node->id, new ListOptions(perPage: 1, cursor: $first->nextCursor));

    expect($named)->toBeInstanceOf(DocumentRevisionSnapshot::class)
        ->and($named->label)->toBe('Release')
        ->and($named->keepForever)->toBeTrue()
        ->and($named->isHead)->toBeTrue()
        ->and($first)->toBeInstanceOf(Page::class)
        ->and($first->items[0]->id)->toBe($named->id)
        ->and($first->nextCursor)->toBeString()
        ->and($rest->items[0]->number)->toBe(1);
});

it('reads a historical revision read-only and refuses a revision of another document', function (): void {
    [, $documents, $node] = documentsFixture('original');
    $original = $documents->revisions($node->id)->items[0];
    $documents->update($node->id, DocumentTestHelpers::content('changed')->toArray());
    $other = $documents->create('Other');
    $foreign = $documents->revisions($other->id)->items[0];

    $historical = $documents->content($node->id, $original);

    expect($historical->editable)->toBeFalse()
        ->and($historical->revision?->id)->toBe($original->id)
        ->and(documentText($historical))->toBe('original')
        ->and(fn () => $documents->content($node->id, $foreign->id))->toThrow(DocumentRevisionNotFoundException::class)
        ->and(fn () => $documents->restore($node->id, $foreign))->toThrow(DocumentRevisionNotFoundException::class)
        ->and(fn () => $documents->setRetention($node->id, $foreign, true))->toThrow(DocumentRevisionNotFoundException::class);
});

it('restores a revision forward with a new epoch and the content precondition', function (): void {
    [, $documents, $node] = documentsFixture('original');
    $original = $documents->revisions($node->id)->items[0];
    $updated = $documents->update($node->id, DocumentTestHelpers::content('changed')->toArray());

    expect(fn () => $documents->restore($node->id, $original, expectedStateVersion: 1))->toThrow(StaleDocumentStateException::class);

    $restored = $documents->restore($node->id, $original->id, expectedStateVersion: 2);

    expect($restored->stateVersion)->toBe(3)
        ->and($restored->epoch)->toBe($updated->epoch + 1)
        ->and($restored->revision?->restoredFromRevisionId)->toBe($original->id)
        ->and(documentText($restored))->toBe('original');
});

it('pins and releases a revision', function (): void {
    [, $documents, $node] = documentsFixture();
    $head = $documents->revisions($node->id)->items[0];

    expect($documents->setRetention($node->id, $head, true)->keepForever)->toBeTrue()
        ->and($documents->setRetention($node->id, $head->id, false)->keepForever)->toBeFalse();
});

it('exports the working state or a chosen revision', function (): void {
    [, $documents, $node] = documentsFixture('exported original');
    $original = $documents->revisions($node->id)->items[0];
    $documents->update($node->id, DocumentTestHelpers::content('exported now')->toArray());

    $now = $documents->export($node->id, 'html');
    $then = $documents->export($node->id, 'html', $original);

    expect($now)->toBeInstanceOf(DocumentExport::class)
        ->and($now->bytes)->toContain('exported now')
        ->and($now->sourceRevisionId)->toBeNull()
        ->and($then->bytes)->toContain('exported original')
        ->and($then->sourceRevisionId)->toBe($original->id);
});

it('refuses a type that is not registered on create, and uses the configured default type', function (): void {
    $documents = Documents::in(DocumentTestHelpers::drive());

    expect(fn () => $documents->create('X', 'acme/unknown'))->toThrow(UnknownDocumentTypeException::class)
        ->and(fn () => $documents->create('S', 'vaulter/spreadsheet'))->toThrow(UnknownDocumentTypeException::class)
        ->and($documents->create('Y')->document?->type)->toBe('vaulter/rich-text');

    config()->set('vaulter-documents.default_type', 'vaulter/markdown');
    expect($documents->create('Z')->document?->type)->toBe('vaulter/markdown');
});

it('applies the drive profile document types on create', function (): void {
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->documentTypes('vaulter/markdown'));
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    $documents = Documents::in(DocumentTestHelpers::drive());

    expect(fn () => $documents->create('Blocked'))->toThrow(ProfileRestrictionException::class)
        ->and($documents->create('Allowed', 'vaulter/markdown')->name)->toBe('Allowed');
});

it('fails or renames on a name conflict as asked', function (): void {
    [, $documents] = documentsFixture();

    expect(fn () => $documents->create('Spec'))->toThrow(NodeNameConflictException::class)
        ->and($documents->create('Spec', options: new CreateFolderOptions(OnNameConflict::Rename))->name)->toBe('Spec (2)');
});

it('refuses an oversized generated conflict name without creating a second document', function (): void {
    $documents = Documents::in(DocumentTestHelpers::drive());
    $name = 'a.'.str_repeat('x', 253);
    $node = $documents->create($name);

    expect(fn () => $documents->create($name, options: new CreateFolderOptions(OnNameConflict::Rename)))
        ->toThrow(InvalidNodeNameException::class)
        ->and(Document::query()->count())->toBe(1)
        ->and($documents->content($node)->stateVersion)->toBe(1);
});

it('refuses document methods on a folder', function (): void {
    $handle = DocumentTestHelpers::drive();
    $folder = $handle->folders()->create('Folder');

    expect(fn () => Documents::in($handle)->content($folder->id))->toThrow(NotADocumentException::class);
});

it('records the acting user on content writes', function (): void {
    [$handle, , $node] = documentsFixture();
    AccessTestHelpers::grant($node, RoleRegistry::WRITER, subjectId: 9);

    $updated = Documents::in($handle->actingAs(AccessTestHelpers::user(9)))->update($node->id, DocumentTestHelpers::content('by nine')->toArray());

    expect($updated->updatedBy)->toBe('9');
});
