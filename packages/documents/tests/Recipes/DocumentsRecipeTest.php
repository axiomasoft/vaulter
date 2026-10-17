<?php

declare(strict_types=1);

use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Data\CreateFolderOptions;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\OnNameConflict;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

// The fragments below are copied into the guides byte for byte; the guides' own `use` lines are
// the imports of this file.
function recipeDrive(): array
{
    $project = DriveTestHelpers::host();
    $user = DriveTestHelpers::actor(Vaulter::drive($project)->asSystem('recipe fixture'), 1);

    return [$project, $user];
}

it('creates, reads, updates, snapshots, exports and comments (documents guide)', function (): void {
    [$project, $user] = recipeDrive();
    $tipTapDoc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello.']]]]];

    // [recipe:documents-operations]
    $drive = Vaulter::drive($project)->actingAs($user);
    $documents = Documents::in($drive);

    $node = $documents->create('Design notes', content: ['type' => 'doc', 'content' => []]);
    $doc = $documents->content($node->id);            // DocumentSnapshot: content, stateVersion, epoch, …
    $doc = $documents->update($node->id, $tipTapDoc, expectedStateVersion: $doc->stateVersion);
    $named = $documents->snapshot($node->id, 'Before review', keepForever: true);
    $html = $documents->export($node->id, 'html')->bytes;

    Documents::comments($drive)->add($node->id, new AddCommentInput('Looks good', anchor: ['from' => 1, 'to' => 4]));
    // [/recipe]

    expect($doc->stateVersion)->toBe(2)
        ->and($named->label)->toBe('Before review')
        ->and($named->keepForever)->toBeTrue()
        ->and($html)->toContain('Hello.')
        ->and(Documents::comments($drive)->list($node->id)->items)->toHaveCount(1);
});

it('creates a document under a folder (documents guide)', function (): void {
    [$project, $user] = recipeDrive();
    $drive = Vaulter::drive($project)->actingAs($user);
    $folder = $drive->folders()->create('Notes');

    // [recipe:documents-create]
    $node = Documents::in($drive)->create(
        name: 'Design notes',
        type: 'vaulter/rich-text',              // or 'vaulter/markdown', 'vaulter/spreadsheet'
        content: [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Hello.'],
                ]],
            ],
        ],
        parent: $folder->id,                     // null = drive root
        options: new CreateFolderOptions(OnNameConflict::Rename),
    );
    // [/recipe]

    expect($node->kind)->toBe(NodeKind::Document)
        ->and($node->parentId)->toBe($folder->id);
});

it('lists the revision history page by page (documents guide)', function (): void {
    [$project, $user] = recipeDrive();
    $drive = Vaulter::drive($project)->actingAs($user);
    $node = Documents::in($drive)->create('History', content: ['type' => 'doc', 'content' => []]);
    foreach (range(1, 51) as $number) {
        Documents::in($drive)->snapshot($node->id, "v{$number}");
    }

    // [recipe:documents-revisions]
    $page = Documents::in($drive)->revisions($node->id, new ListOptions(perPage: 50));
    $next = Documents::in($drive)->revisions($node->id, new ListOptions(perPage: 50, cursor: $page->nextCursor));
    // [/recipe]

    expect($page->items)->toHaveCount(50)
        ->and($page->nextCursor)->not->toBeNull()
        ->and($next->items)->toHaveCount(1)
        ->and($next->nextCursor)->toBeNull()
        ->and($next->items[0]->id)->not->toBe($page->items[0]->id);
});
