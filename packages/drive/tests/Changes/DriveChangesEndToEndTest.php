<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Drives\PurgeDriveStepAction;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Enums\Changes\ChangeOperation;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;

/**
 * A syncing client end to end (PLAN2.D50, D59): it lists the drive once, takes the
 * start cursor, then only applies journal pages — upserts, removes that take a folder's
 * subtree along, and subtree re-reads by listing. After a mixed series of mutations
 * its replica equals what a fresh listing shows the same reader.
 */
uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    $this->drive = DriveTestHelpers::drive();
    $this->root = $this->drive->root();
    // User 1 reads the whole drive except "private", which stops inheriting.
    AccessTestHelpers::grant($this->root, RoleRegistry::READER);
    $this->private = $this->drive->folders()->create('private');
    $this->drive->access()->restrictInheritance($this->private);
    $this->reader = $this->drive->actingAs(AccessTestHelpers::user(1));
});

/** @return array<string, array{parent: ?string, name: string}> every node under $folder the reader may list */
function changesListing(DriveHandle $reader, string $folder): array
{
    $nodes = [];
    $cursor = null;
    do {
        $page = $reader->nodes()->children($folder, new ListOptions(perPage: 200, cursor: $cursor));
        foreach ($page->items as $node) {
            /** @var NodeSnapshot $node */
            $nodes[$node->id] = ['parent' => $node->parentId, 'name' => $node->name];
            if ($node->kind === NodeKind::Folder) {
                $nodes += changesListing($reader, $node->id);
            }
        }
        $cursor = $page->nextCursor;
    } while ($cursor !== null);

    return $nodes;
}

/** @param array<string, array{parent: ?string, name: string}> $replica */
function changesForget(array &$replica, string $id): void
{
    unset($replica[$id]);
    foreach ($replica as $child => $node) {
        if ($node['parent'] === $id) {
            changesForget($replica, (string) $child);
        }
    }
}

/**
 * Apply every page after $cursor to $replica; returns the cursor to continue from.
 *
 * @param array<string, array{parent: ?string, name: string}> $replica
 */
function changesApply(DriveHandle $reader, array &$replica, string $cursor): string
{
    do {
        $page = $reader->changes()->list($cursor, limit: 3);
        foreach ($page->changes as $change) {
            if ($change->operation === ChangeOperation::Remove) {
                changesForget($replica, $change->nodeId);

                continue;
            }
            $replica[$change->nodeId] = ['parent' => $change->node->parentId, 'name' => $change->node->name];
            if ($change->subtree) {
                foreach (array_keys($replica) as $id) {
                    if (($replica[$id]['parent'] ?? null) === $change->nodeId) {
                        changesForget($replica, (string) $id);
                    }
                }
                $replica += changesListing($reader, $change->nodeId);
            }
        }
        $cursor = $page->cursor;
    } while ($page->hasMore);

    return $cursor;
}

it('keeps a replica built from the journal equal to a fresh listing', function (): void {
    $replica = changesListing($this->reader, $this->root->id);
    $cursor = $this->reader->changes()->startCursor();
    expect($replica)->not->toHaveKey($this->private->id);

    $projects = $this->drive->folders()->create('projects');
    $alpha = $this->drive->folders()->create('alpha', $projects);
    // At the root, so no later subtree re-read of a folder repairs a missed remove.
    $file = $this->drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('spec'), 'spec.txt'));
    $this->drive->folders()->create('notes', $alpha);
    $cursor = changesApply($this->reader, $replica, $cursor);
    expect($replica)->toEqual(changesListing($this->reader, $this->root->id));

    $this->drive->nodes()->rename($alpha, 'alpha-2');
    $this->drive->nodes()->move($file, $this->private);
    // Renamed out of sight: the move entry must survive this weaker one (PLAN2.D61).
    $this->drive->nodes()->rename($file, 'spec-moved.txt');
    $copy = $this->drive->nodes()->copy($alpha, $projects, new CopyOptions(name: 'alpha-copy'));
    $this->drive->nodes()->move($alpha, $this->root);
    $cursor = changesApply($this->reader, $replica, $cursor);
    expect($replica)->toEqual(changesListing($this->reader, $this->root->id))
        ->and($replica)->not->toHaveKey($file->id)
        ->and($replica)->toHaveKey($copy->id);

    $this->drive->nodes()->trash($projects);
    $cursor = changesApply($this->reader, $replica, $cursor);
    expect($replica)->toEqual(changesListing($this->reader, $this->root->id))
        ->and($replica)->not->toHaveKey($copy->id);

    $this->drive->trash()->restore($projects);
    $grant = $this->drive->access()->grant($this->private, new GrantInput(subject: Subject::ofModel(AccessTestHelpers::user(1)), role: RoleRegistry::READER));
    $cursor = changesApply($this->reader, $replica, $cursor);
    expect($replica)->toEqual(changesListing($this->reader, $this->root->id))
        ->and($replica)->toHaveKey($file->id)
        ->and($replica)->toHaveKey($copy->id);

    $this->drive->nodes()->rename($file, 'spec-final.txt');
    $this->drive->access()->revoke($this->private, $grant);
    changesApply($this->reader, $replica, $cursor);
    expect($replica)->toEqual(changesListing($this->reader, $this->root->id))
        ->and($replica)->not->toHaveKey($this->private->id)
        ->and($replica)->not->toHaveKey($file->id);
});

it('deletes the drive\'s journal with the drive', function (): void {
    $this->drive->folders()->create('doomed');
    $driveId = $this->drive->model()->getKey();
    $this->drive->delete();

    while (!app(PurgeDriveStepAction::class)->execute($driveId)) {
        // Each step purges a batch; the last one removes the drive row.
    }

    expect(app(VaulterDatabase::class)->connection()->table('v_drive_changes')->where('drive_id', $driveId)->count())->toBe(0)
        ->and(app(VaulterDatabase::class)->connection()->table('v_drive_change_heads')->where('drive_id', $driveId)->count())->toBe(0);
});
