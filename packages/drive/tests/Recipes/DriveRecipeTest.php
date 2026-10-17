<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\RestoreOptions;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotTrashedException;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Facades\Vaulter;

// The fragments below are copied into the guides byte for byte; the guides' own `use` lines are
// the imports of this file.
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('opens a drive, creates a folder, uploads and lists (quick start)', function (): void {
    $project = DriveTestHelpers::host();
    $user = DriveTestHelpers::actor(Vaulter::drive($project)->asSystem('recipe fixture'), 1);
    request()->files->set('document', UploadedFile::fake()->createWithContent('q3.pdf', 'quarterly numbers'));

    // [recipe:quick-start-open]
    // $project is any Eloquent model; $user is the signed-in user who acts on the drive.
    $drive = Vaulter::drive($project)->actingAs($user);

    // Create a folder under the root.
    $reports = $drive->folders()->create('Reports');

    // Upload bytes — a path, an UploadedFile, a stream or a string, wrapped in an UploadSource.
    $node = $drive->files()->upload(
        UploadSource::fromUploadedFile(request()->file('document')),
        new UploadOptions(parent: $reports),
    );

    // List the direct children of a folder (null = the root).
    $page = $drive->nodes()->children($reports);   // Vaulter\Data\Page of NodeSnapshot
    // [/recipe]

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->id)->toBe($node->id)
        ->and($node->name)->toBe('q3.pdf');
});

it('renames, moves, copies, trashes and adds a revision (quick start)', function (): void {
    $project = DriveTestHelpers::host();
    $user = DriveTestHelpers::actor(Vaulter::drive($project)->asSystem('recipe fixture'), 1);
    $drive = Vaulter::drive($project)->actingAs($user);
    $reports = $drive->folders()->create('Reports');
    $node = $drive->files()->upload(UploadSource::fromString('v1', 'q3.bin'));
    $newBytes = 'v2 bytes';

    // [recipe:quick-start-methods]
    $drive->nodes()->rename($node, 'Q3 report.pdf');
    $drive->nodes()->move($node, $reports);
    $drive->nodes()->copy($node, $reports, new CopyOptions(name: 'Q3 report (copy).pdf'));
    $drive->nodes()->trash($node);                         // soft trash (recoverable)
    $drive->trash()->restore($node);
    $drive->files()->addRevision($node, UploadSource::fromString($newBytes, 'Q3 report.pdf')); // a new revision of a file
    // [/recipe]

    $names = collect($drive->nodes()->children($reports)->items)->pluck('name')->sort()->values()->all();
    expect($names)->toBe(['Q3 report (copy).pdf', 'Q3 report.pdf'])
        ->and($drive->files()->revisions($node)->items)->toHaveCount(2);
});

it('trashes, restores and purges (trash guide)', function (): void {
    $project = DriveTestHelpers::host();
    $user = DriveTestHelpers::actor(Vaulter::drive($project)->asSystem('recipe fixture'), 1);
    $setup = Vaulter::drive($project)->actingAs($user);
    $node = $setup->folders()->create('Old');
    $folder = $setup->folders()->create('Archive');

    // [recipe:trash-trash]
    $drive = Vaulter::drive($project)->actingAs($user);

    $drive->nodes()->trash($node);          // soft-delete — dispatches NodeTrashed
    // [/recipe]
    expect($drive->nodes()->children()->items)->toHaveCount(1);

    // [recipe:trash-restore]
    $drive->trash()->restore($node);        // back to live — dispatches NodeRestored
    // [/recipe]
    expect($drive->nodes()->children()->items)->toHaveCount(2);

    $drive->nodes()->trash($node);
    // [recipe:trash-restore-destination]
    $drive->trash()->restore($node, new RestoreOptions(destination: $folder));
    // [/recipe]
    expect($drive->nodes()->find($node)->parentId)->toBe($folder->id);

    $drive->nodes()->trash($node);
    // [recipe:trash-purge]
    $drive->trash()->purge($node);          // permanent — dispatches NodePurged
    // [/recipe]
    expect(fn () => $drive->nodes()->find($node))->toThrow(NodeNotFoundException::class);
});

it('refuses to purge a live node (trash guide)', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->folders()->create('Live');

    expect(fn () => $drive->trash()->purge($node))->toThrow(NodeNotTrashedException::class);
});

it('uploads under the open driver in a host test (recipes guide)', function (): void {
    $task = DriveTestHelpers::host();
    $user = DriveTestHelpers::actor(Vaulter::drive($task)->asSystem('recipe fixture'), 1);

    // [recipe:testing-open-driver]
    // In a Pest test, force the open driver for the duration of the test.
    config()->set('vaulter.access.permission_driver', 'null'); // non-production only

    $drive = Vaulter::drive($task)->actingAs($user);
    $node = $drive->files()->upload(UploadSource::fromString('hello', 'note.txt'));

    expect($node->name)->toBe('note.txt');
    // [/recipe]
});

it('reads the abilities of one node and of a listing (abilities guide)', function (): void {
    $project = DriveTestHelpers::host();
    $user = DriveTestHelpers::actor(Vaulter::drive($project)->asSystem('recipe fixture'), 1);
    $drive = Vaulter::drive($project)->actingAs($user);
    $node = $drive->folders()->create('Plans');
    $other = $drive->folders()->create('Notes');

    // [recipe:abilities-read]
    $abilities = $drive->nodes()->abilities($node);   // NodeAbilities
    $canEdit = $abilities->flags['edit'];             // ability name => allowed

    // One resolver pass for a whole listing instead of one call per row.
    $byNode = $drive->nodes()->abilitiesMany([$node, $other]);   // node id => NodeAbilities
    // [/recipe]

    expect($canEdit)->toBeTrue()
        ->and($byNode)->toHaveKeys([(string) $node->id, (string) $other->id])
        ->and($abilities->toArray())->toHaveKeys(['view', 'download', 'comment', 'edit', 'create', 'move', 'copy', 'trash', 'restore', 'share', 'manage', 'delete']);
});
