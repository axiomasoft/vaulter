<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Contracts\DriveHandle as DriveHandleContract;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Events\Nodes\NodePurged;
use Vaulter\Drive\Exceptions\Nodes\TreeTraversalIncompleteException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Profiles\DrivePolicyBuilder;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Facades\Vaulter;

beforeEach(fn () => Storage::fake('local'));

function deepDrive(int $maxDepth): DriveHandleContract
{
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->drivePolicy(static fn (DrivePolicyBuilder $p) => $p->maxFolderDepth($maxDepth)));
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    app()->forgetInstance(DriveProvisioner::class);

    return Vaulter::drive(DriveTestHelpers::host())->asSystem('deep fixture');
}

/** @return array{0: list<string>, 1: string} chain ids top-down and the file node id at the bottom */
function deepChain(DriveHandleContract $drive, int $levels): array
{
    $ids = [];
    $parent = null;
    for ($level = 1; $level <= $levels; $level++) {
        $folder = $drive->folders()->create('d'.$level, $parent);
        $ids[] = (string) $folder->id;
        $parent = $folder;
    }
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('deep bytes'), 'deep.bin'), new UploadOptions(parent: $parent));

    return [$ids, (string) $file->id];
}

it('copy, trash and purge see exactly the full set of a deep tree', function (int $levels, int $maxDepth): void {
    $drive = deepDrive($maxDepth);
    [$ids, $fileId] = deepChain($drive, $levels);
    $all = [...$ids, $fileId];
    $top = $ids[0];

    // COPY: every member, no more, no less.
    $before = Node::query()->count();
    $copy = $drive->nodes()->copy($top);
    expect(Node::query()->count() - $before)->toBe(count($all))
        ->and($copy->id)->not->toBe($top);

    // TRASH: the whole chain gets the same explicit root, nothing outside it.
    $drive->nodes()->trash($top);
    expect(Node::query()->whereKey($all)->whereNotNull('trashed_at')->count())->toBe(count($all))
        ->and(Node::query()->whereKey($all)->where('trash_root_id', $top)->count())->toBe(count($all));

    // PURGE: exactly those ids leave, with the same ids in the event.
    Event::fake([NodePurged::class]);
    $drive->trash()->purge($top);
    expect(Node::query()->whereKey($all)->count())->toBe(0)
        ->and(Node::query()->whereKey($copy->id)->count())->toBe(1);
    Event::assertDispatched(NodePurged::class, function (NodePurged $event) use ($all): bool {
        $given = array_map('strval', $event->nodeIds);
        sort($given);
        $expected = $all;
        sort($expected);

        return $given === $expected;
    });
})->with([
    'depth 65 (default profile)' => [65, 100],
    'depth 100 (default profile)' => [99, 100],
    'depth above 100 (profile allows 130)' => [115, 130],
]);

it('fails closed before any write when a tree is deeper than the traversal can prove', function (): void {
    $drive = deepDrive(100);
    $top = $drive->folders()->create('top');
    // Legacy rows deeper than any profile allows, written below the policy.
    $parent = DriveTestHelpers::model($top);
    $driveId = $parent->drive_id;
    $last = $parent;
    for ($level = 0; $level < 120; $level++) {
        $id = DB::table($last->getTable())->insertGetId(['drive_id' => $driveId, 'parent_id' => $last->getKey(), 'kind' => 'folder', 'name' => 'x'.$level,
            'name_key' => 'x'.$level, 'created_at' => now(), 'updated_at' => now()]);
        $last = Node::query()->findOrFail($id);
    }
    $before = Node::query()->whereNotNull('trashed_at')->count();

    expect(fn () => $drive->nodes()->trash($top))->toThrow(TreeTraversalIncompleteException::class)
        ->and(fn () => $drive->nodes()->copy($top))->toThrow(TreeTraversalIncompleteException::class)
        ->and(Node::query()->whereNotNull('trashed_at')->count())->toBe($before);
});
