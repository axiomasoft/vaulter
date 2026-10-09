<?php

declare(strict_types=1);

use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;

it('exports the working state with its epoch and state version and no revision id', function (): void {
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);
    $node = $documents->create('Source', content: DocumentTestHelpers::content('one')->toArray());
    $documents->update($node->id, DocumentTestHelpers::content('two')->toArray());
    $stored = DocumentTestHelpers::document(DriveTestHelpers::model($node));

    $export = $documents->export($node->id, 'html');

    expect($export->bytes)->toContain('two')
        ->and($export->sourceRevisionId)->toBeNull()
        ->and($export->sourceStateVersion)->toBe($stored->state_version)
        ->and($export->sourceEpoch)->toBe($stored->collab_epoch)
        ->and($export->format)->toBe('html')
        ->and($export->mimeType)->toBe('text/html')
        ->and($export->filename)->toBe('Source.html');
});

it('exports a chosen checkpoint with its own id and state version', function (): void {
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);
    $node = $documents->create('Source', content: DocumentTestHelpers::content('original')->toArray());
    $first = $documents->revisions($node->id)->items[0];
    $documents->update($node->id, DocumentTestHelpers::content('later')->toArray());

    $export = $documents->export($node->id, 'markdown', $first->id);

    expect($export->bytes)->toContain('original')->not->toContain('later')
        ->and($export->sourceRevisionId)->toBe($first->id)
        ->and($export->sourceStateVersion)->toBe($first->stateVersion)
        ->and($export->filename)->toBe('Source.md');
});

it('never creates a revision or moves the validators', function (): void {
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);
    $node = $documents->create('Source', content: DocumentTestHelpers::content('steady')->toArray());
    $documents->update($node->id, DocumentTestHelpers::content('dirty working state')->toArray());
    $model = DriveTestHelpers::model($node);
    $before = DocumentTestHelpers::document($model)->only(['state_version', 'collab_epoch', 'revision_count', 'current_revision_id', 'checkpoint_state_version']);
    $revisions = DocumentRevision::query()->count();

    $documents->export($node->id, 'html');
    $documents->export($node->id, 'markdown');

    expect(DocumentTestHelpers::document($model)->only(['state_version', 'collab_epoch', 'revision_count', 'current_revision_id', 'checkpoint_state_version']))->toBe($before)
        ->and(DocumentRevision::query()->count())->toBe($revisions);
});

it('refuses a revision of another document', function (): void {
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);
    $one = $documents->create('One', content: DocumentTestHelpers::content('one')->toArray());
    $two = $documents->create('Two', content: DocumentTestHelpers::content('two')->toArray());
    $foreign = $documents->revisions($two->id)->items[0];

    expect(fn () => $documents->export($one->id, 'html', $foreign->id))->toThrow(DocumentRevisionNotFoundException::class);
});
