<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Drive\Exceptions\Nodes\NodeCycleException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotTrashedException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Repositories\NodeStoreRepository;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Http\DriveV1;
use Vaulter\Testing\Fixtures\TestUser;

beforeEach(fn () => DriveTestHelpers::insideOperation());

/**
 * The tree invariants: every parent is a live folder of the same drive, the
 * root is never an operand, only the root has no parent, and deletion goes through
 * the trash. Exercised through the PHP API and the REST surface.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

/**
 * A destination that is not a live folder of the drive.
 */
function invalidDestination(DriveHandle $drive, string $kind): Node|NodeSnapshot
{
    return match ($kind) {
        'file' => $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('bytes'), 'target.bin')),
        // Drive cannot depend on documents; a document node is a plain kind=document row.
        'document' => app(NodeSnapshotMapper::class)->map(app(NodeStoreRepository::class)->create([
            'drive_id' => $drive->model()->getKey(),
            'parent_id' => $drive->root()->id,
            'kind' => NodeKind::Document,
            'name' => 'Spec',
            'size_bytes' => 0,
        ])),
        'trashed folder' => $drive->nodes()->trash($drive->folders()->create('Gone')),
    };
}

$destinations = ['file', 'document', 'trashed folder'];

it('rejects creating a folder under a non-folder or trashed destination', function (string $kind): void {
    $drive = DriveTestHelpers::drive();
    $destination = invalidDestination($drive, $kind);

    expect(fn (): NodeSnapshot => $drive->folders()->create('Child', $destination))->toThrow($kind === 'trashed folder' ? NodeNotFoundException::class : InvalidDestinationException::class);

    $this->postJson('api/vaulter/v1/drives/'.$drive->model()->getKey().'/folders', [
        'name' => 'Child',
        'parent_id' => $destination->id,
    ])->assertStatus($kind === 'trashed folder' ? 404 : 409)->assertJsonPath('code', $kind === 'trashed folder' ? 'node_not_found' : 'invalid_destination');
})->with($destinations);

it('rejects uploading a file into a non-folder or trashed destination', function (string $kind): void {
    $drive = DriveTestHelpers::drive();
    $destination = invalidDestination($drive, $kind);

    expect(fn (): NodeSnapshot => $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('x'), 'x.bin'), new UploadOptions(parent: $destination)))
        ->toThrow($kind === 'trashed folder' ? NodeNotFoundException::class : InvalidDestinationException::class);

    $this->postJson('api/vaulter/v1/drives/'.$drive->model()->getKey().'/files', [
        'parent_id' => $destination->id,
        'file' => UploadedFile::fake()->createWithContent('x.txt', 'x'),
    ])->assertStatus($kind === 'trashed folder' ? 404 : 409)->assertJsonPath('code', $kind === 'trashed folder' ? 'node_not_found' : 'invalid_destination');
})->with($destinations);

it('rejects moving a node into a non-folder or trashed destination', function (string $kind): void {
    $drive = DriveTestHelpers::drive();
    $destination = invalidDestination($drive, $kind);
    $node = $drive->folders()->create('Mover');

    expect(fn (): NodeSnapshot => $drive->nodes()->move($node, $destination))->toThrow($kind === 'trashed folder' ? NodeNotFoundException::class : InvalidDestinationException::class);

    $this->postJson('api/vaulter/v1/nodes/'.$node->id.'/move', ['destination_id' => $destination->id], DriveV1::ifMatch($node->id))
        ->assertStatus($kind === 'trashed folder' ? 404 : 409)
        ->assertJsonPath('code', $kind === 'trashed folder' ? 'node_not_found' : 'invalid_destination');
})->with($destinations);

it('rejects copying a node into a non-folder or trashed destination', function (string $kind): void {
    $drive = DriveTestHelpers::drive();
    $destination = invalidDestination($drive, $kind);
    $node = $drive->folders()->create('Source');

    expect(fn (): NodeSnapshot => $drive->nodes()->copy($node, $destination))->toThrow($kind === 'trashed folder' ? NodeNotFoundException::class : InvalidDestinationException::class);

    $this->postJson('api/vaulter/v1/nodes/'.$node->id.'/copy', ['destination_id' => $destination->id])
        ->assertStatus($kind === 'trashed folder' ? 404 : 409)
        ->assertJsonPath('code', $kind === 'trashed folder' ? 'node_not_found' : 'invalid_destination');
})->with($destinations);

it('refuses a restore whose original parent is no longer a live folder and restores to an explicit destination', function (): void {
    $drive = DriveTestHelpers::drive();
    $parent = $drive->folders()->create('Parent');
    $child = $drive->folders()->create('Child', $parent);

    $drive->nodes()->trash($child);
    $drive->nodes()->trash($parent);

    $this->postJson('api/vaulter/v1/nodes/'.$child->id.'/restore', [], DriveV1::ifMatch($child->id))
        ->assertStatus(409)
        ->assertJsonPath('code', 'restore_destination_required');
    expect(DriveTestHelpers::model($child)->trashed_at)->not->toBeNull();

    $this->postJson('api/vaulter/v1/nodes/'.$child->id.'/restore', ['destination_id' => (string) $drive->model()->root_node_id], DriveV1::ifMatch($child->id))
        ->assertOk()
        ->assertJsonPath('data.parent_id', (string) $drive->model()->root_node_id);
});

it('refuses to restore a node that is not in the trash', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->folders()->create('Live');

    expect(fn (): NodeSnapshot => $drive->trash()->restore($node))->toThrow(NodeNotTrashedException::class);

    $this->postJson('api/vaulter/v1/nodes/'.$node->id.'/restore', [], DriveV1::ifMatch($node->id))
        ->assertStatus(409)
        ->assertJsonPath('code', 'node_not_trashed');
});

it('rejects a folder of another drive as the destination', function (string $operation): void {
    $drive = DriveTestHelpers::drive();
    $foreign = DriveTestHelpers::drive()->folders()->create('Elsewhere');
    $node = $drive->folders()->create('Local');

    $run = match ($operation) {
        'create folder' => fn (): NodeSnapshot => $drive->folders()->create('Child', $foreign),
        'upload' => fn (): NodeSnapshot => $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('x'), 'x.bin'), new UploadOptions(parent: $foreign)),
        'move' => fn (): NodeSnapshot => $drive->nodes()->move($node, $foreign),
        'copy' => fn (): NodeSnapshot => $drive->nodes()->copy($node, $foreign),
    };

    expect($run)->toThrow(NodeNotFoundException::class);

    // Over HTTP a foreign node id is not addressable through this drive at all.
    $this->postJson('api/vaulter/v1/nodes/'.$node->id.'/move', ['destination_id' => $foreign->id], DriveV1::ifMatch($node->id))
        ->assertNotFound();
})->with(['create folder', 'upload', 'move', 'copy']);

it('hides a foreign destination as 404 node_not_found', function (): void {
    $drive = DriveTestHelpers::drive();
    $foreign = DriveTestHelpers::drive()->folders()->create('Elsewhere');

    $exception = rescue(fn (): NodeSnapshot => $drive->folders()->create('Child', $foreign), fn (Throwable $e): Throwable => $e, report: false);

    expect($exception)->toBeInstanceOf(NodeNotFoundException::class);

    expect($exception->httpStatus())->toBe(404)
        ->and($exception->problemCode())->toBe('node_not_found');
});

it('never renames, moves, trashes or deletes the root', function (string $operation): void {
    $drive = DriveTestHelpers::drive();
    $root = $drive->root();
    $folder = $drive->folders()->create('Folder');

    $run = match ($operation) {
        'rename' => fn (): NodeSnapshot => $drive->nodes()->rename($root, 'Renamed'),
        'move' => fn (): NodeSnapshot => $drive->nodes()->move($root, $folder),
        'trash' => fn (): NodeSnapshot => $drive->nodes()->trash($root),
        'force delete' => fn () => $drive->trash()->purge($root),
        'copy' => fn (): NodeSnapshot => $drive->nodes()->copy($root, $folder),
    };

    expect($run)->toThrow(InvalidDestinationException::class, 'drive root');

    $request = match ($operation) {
        'rename' => fn () => $this->patchJson('api/vaulter/v1/nodes/'.$root->id, ['name' => 'Renamed'], DriveV1::ifMatch($root->id)),
        'move' => fn () => $this->postJson('api/vaulter/v1/nodes/'.$root->id.'/move', ['destination_id' => $folder->id], DriveV1::ifMatch($root->id)),
        'trash' => fn () => $this->postJson('api/vaulter/v1/nodes/'.$root->id.'/trash', [], DriveV1::ifMatch($root->id)),
        'force delete' => fn () => $this->deleteJson('api/vaulter/v1/nodes/'.$root->id, [], DriveV1::ifMatch($root->id)),
        'copy' => fn () => $this->postJson('api/vaulter/v1/nodes/'.$root->id.'/copy', ['destination_id' => $folder->id]),
    };

    $request()->assertStatus(409)->assertJsonPath('code', 'invalid_destination');

    expect(DriveTestHelpers::model($root)->parent_id)->toBeNull()
        ->and($root->trashedAt)->toBeNull()
        ->and($root->name)->toBe('');
})->with(['rename', 'move', 'trash', 'force delete', 'copy']);

it('only force-deletes from the trash', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->folders()->create('Live');

    expect(fn () => $drive->trash()->purge($node))->toThrow(NodeNotTrashedException::class);

    $this->deleteJson('api/vaulter/v1/nodes/'.$node->id, [], DriveV1::ifMatch($node->id))
        ->assertStatus(409)
        ->assertJsonPath('code', 'node_not_trashed');

    expect(Node::query()->find($node->id))->not->toBeNull();
});

it('refuses to trash a node that is already in the trash', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->nodes()->trash($drive->folders()->create('Twice'));

    expect(fn (): NodeSnapshot => $drive->nodes()->trash($node))->toThrow(NodeNotFoundException::class);
});

it('rejects moving a folder into its own subtree', function (): void {
    $drive = DriveTestHelpers::drive();
    $parent = $drive->folders()->create('Parent');
    $child = $drive->folders()->create('Child', $parent);

    expect(fn (): NodeSnapshot => $drive->nodes()->move($parent, $child))->toThrow(NodeCycleException::class)
        ->and(fn (): NodeSnapshot => $drive->nodes()->move($parent, $parent))->toThrow(NodeCycleException::class);
});

it('resolves a missing destination to the root instead of a parentless node', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Nested', $drive->folders()->create('Top'));
    $storageId = $drive->model()->getKey();
    $rootId = $drive->model()->root_node_id;

    $this->postJson('api/vaulter/v1/drives/'.$storageId.'/folders', ['name' => 'Created'])
        ->assertJsonPath('data.parent_id', (string) $rootId);
    $this->postJson('api/vaulter/v1/drives/'.$storageId.'/files', ['file' => UploadedFile::fake()->createWithContent('u.txt', 'u')])
        ->assertJsonPath('data.parent_id', (string) $rootId);
    $this->postJson('api/vaulter/v1/nodes/'.$folder->id.'/copy', ['destination_id' => null, 'name' => 'Copied'])
        ->assertJsonPath('data.parent_id', (string) $rootId);
    $this->postJson('api/vaulter/v1/nodes/'.$folder->id.'/move', ['destination_id' => null], DriveV1::ifMatch($folder->id))
        ->assertJsonPath('data.parent_id', (string) $rootId);

    expect(Node::query()->where('drive_id', $storageId)->whereNull('parent_id')->pluck('id')->all())
        ->toEqual([$rootId]);
});

it('allows exactly one parentless node per drive', function (): void {
    $drive = DriveTestHelpers::drive();

    expect(fn () => app(NodeStoreRepository::class)->create([
        'drive_id' => $drive->model()->getKey(),
        'parent_id' => null,
        'kind' => NodeKind::Folder,
        'name' => 'Second root',
        'size_bytes' => 0,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it("restores to the parent recorded on the locked row, not the caller's stale model", function (): void {
    $drive = DriveTestHelpers::drive();
    $a = $drive->folders()->create('A');
    $b = $drive->folders()->create('B');
    $stale = $drive->folders()->create('Node', $a);

    // Another request moves the node to B and trashes it; the caller still holds the old model.
    $fresh = Node::query()->findOrFail($stale->id);
    $drive->nodes()->move($fresh->id, $b);
    $drive->nodes()->trash($fresh->id);

    expect($drive->trash()->restore($stale)->parentId)->toEqual($b->id);
});

/** PostgreSQL takes the tree lock as an advisory lock; SQLite and MySQL as the storage row. */
function treeLockStatement(string $sql): bool
{
    return DB::connection()->getDriverName() === 'pgsql'
        ? str_contains($sql, 'pg_advisory_xact_lock')
        : str_starts_with($sql, 'select "id" from "v_drives"');
}

it('takes the tree lock before any node row lock', function (string $operation): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Target');
    $source = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('bytes'), 'source.bin'));
    $queries = [];

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        // MySQL quotes identifiers with backticks, the other engines with double quotes
        $queries[] = str_replace('`', '"', $query->sql);
    });

    match ($operation) {
        'create folder' => $drive->folders()->create('Child', $folder),
        'upload' => $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('x'), 'x.bin'), new UploadOptions(parent: $folder)),
        'copy' => $drive->nodes()->copy($source, $folder),
        'trash' => $drive->nodes()->trash($folder),
        'rename' => $drive->nodes()->rename($folder, 'Renamed'),
    };

    $storageLock = array_key_first(array_filter($queries, treeLockStatement(...)));
    $nodeLock = array_key_first(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "v_nodes"') && str_contains($sql, 'order by')));

    expect($storageLock)->not->toBeNull()
        ->and($nodeLock)->not->toBeNull()
        ->and($storageLock)->toBeLessThan($nodeLock);
})->with(['create folder', 'upload', 'copy', 'trash', 'rename']);

it("locks a new version's target before the CAS lock", function (): void {
    $drive = DriveTestHelpers::drive();
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('v1'), 'f.bin'));
    $queries = [];

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        // MySQL quotes identifiers with backticks, the other engines with double quotes
        $queries[] = str_replace('`', '"', $query->sql);
    });

    $drive->files()->addRevision($file, UploadSource::fromStream(DriveTestHelpers::streamFor('v2 bytes'), $file->name));

    $nodeLock = array_key_first(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "v_nodes"') && str_contains($sql, 'order by')));
    $blobWork = array_key_first(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"v_blobs"')));

    expect($nodeLock)->not->toBeNull()
        ->and($blobWork)->not->toBeNull()
        ->and($nodeLock)->toBeLessThan($blobWork)
        ->and($drive->model()->refresh()->used_bytes)->toBe(2 + 8);
});

it('refuses a new version for a node deleted after it was loaded', function (): void {
    $drive = DriveTestHelpers::drive();
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('v1'), 'f.bin'));
    $stale = Node::query()->findOrFail($file->id);

    $drive->nodes()->trash($file);
    $drive->trash()->purge($file);

    expect(fn (): FileRevision => $drive->files()->addRevision($stale->id, UploadSource::fromStream(DriveTestHelpers::streamFor('v2'), $stale->name)))->toThrow(NodeNotFoundException::class);
});

it('purges trash under the tree lock taken before the trashed root row', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->nodes()->trash($drive->folders()->create('Old'));
    $this->travel(400)->days();
    $queries = [];

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        // MySQL quotes identifiers with backticks, the other engines with double quotes
        $queries[] = str_replace('`', '"', $query->sql);
    });

    $this->artisan('vaulter:trash:purge', ['--days' => 1])->assertSuccessful();

    $treeLock = array_key_first(array_filter($queries, treeLockStatement(...)));
    $rootLock = array_key_first(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "v_nodes"') && str_contains($sql, '"trashed_at" <=') && str_contains($sql, 'limit 1')));

    expect($treeLock)->not->toBeNull()
        ->and($rootLock)->not->toBeNull()
        ->and($treeLock)->toBeLessThan($rootLock);
});
