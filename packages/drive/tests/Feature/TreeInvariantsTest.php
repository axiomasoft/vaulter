<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Drive\Exceptions\Nodes\NodeCycleException;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\NodeNotTrashedException;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The tree invariants (D13): every parent is a live folder of the same drive, the
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
function invalidDestination(DriveHandle $drive, string $kind): Node
{
    return match ($kind) {
        'file' => $drive->upload(DriveTestHelpers::streamFor('bytes'), originalName: 'target.bin'),
        // Drive cannot depend on documents; a document node is a plain kind=document row.
        'document' => app(NodeStoreRepository::class)->create([
            'drive_id' => $drive->model()->getKey(),
            'parent_id' => $drive->root()->getKey(),
            'kind' => NodeKind::Document,
            'name' => 'Spec',
            'size_bytes' => 0,
        ]),
        'trashed folder' => $drive->trash($drive->createFolder('Gone')),
    };
}

$destinations = ['file', 'document', 'trashed folder'];

it('rejects creating a folder under a non-folder or trashed destination', function (string $kind): void {
    $drive = DriveTestHelpers::drive();
    $destination = invalidDestination($drive, $kind);

    expect(fn (): Node => $drive->createFolder('Child', $destination))->toThrow(InvalidDestinationException::class);

    $this->postJson('api/vaulter/storages/'.$drive->model()->getKey().'/folders', [
        'name' => 'Child',
        'parent_id' => $destination->getKey(),
    ])->assertStatus(409)->assertJsonPath('code', 'invalid_destination');
})->with($destinations);

it('rejects uploading a file into a non-folder or trashed destination', function (string $kind): void {
    $drive = DriveTestHelpers::drive();
    $destination = invalidDestination($drive, $kind);

    expect(fn (): Node => $drive->upload(DriveTestHelpers::streamFor('x'), into: $destination, originalName: 'x.bin'))
        ->toThrow(InvalidDestinationException::class);

    $this->postJson('api/vaulter/storages/'.$drive->model()->getKey().'/uploads', [
        'parent_id' => $destination->getKey(),
        'file' => UploadedFile::fake()->createWithContent('x.txt', 'x'),
    ])->assertStatus(409)->assertJsonPath('code', 'invalid_destination');
})->with($destinations);

it('rejects moving a node into a non-folder or trashed destination', function (string $kind): void {
    $drive = DriveTestHelpers::drive();
    $destination = invalidDestination($drive, $kind);
    $node = $drive->createFolder('Mover');

    expect(fn (): Node => $drive->move($node, $destination))->toThrow(InvalidDestinationException::class);

    $this->postJson('api/vaulter/nodes/'.$node->getKey().'/move', ['parent_id' => $destination->getKey()])
        ->assertStatus(409)
        ->assertJsonPath('code', 'invalid_destination');
})->with($destinations);

it('rejects copying a node into a non-folder or trashed destination', function (string $kind): void {
    $drive = DriveTestHelpers::drive();
    $destination = invalidDestination($drive, $kind);
    $node = $drive->createFolder('Source');

    expect(fn (): Node => $drive->copy($node, $destination))->toThrow(InvalidDestinationException::class);

    $this->postJson('api/vaulter/nodes/'.$node->getKey().'/copy', ['parent_id' => $destination->getKey()])
        ->assertStatus(409)
        ->assertJsonPath('code', 'invalid_destination');
})->with($destinations);

it('re-homes a restore to the root when the original parent is no longer a live folder', function (): void {
    $drive = DriveTestHelpers::drive();
    $parent = $drive->createFolder('Parent');
    $child = $drive->createFolder('Child', $parent);

    $drive->trash($child);
    $drive->trash($parent);

    $this->postJson('api/vaulter/nodes/'.$child->getKey().'/restore')
        ->assertOk()
        ->assertJsonPath('data.parent_id', $drive->model()->root_node_id);
});

it('refuses to restore a node that is not in the trash', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->createFolder('Live');

    expect(fn (): Node => $drive->restore($node))->toThrow(NodeNotTrashedException::class);

    $this->postJson('api/vaulter/nodes/'.$node->getKey().'/restore')
        ->assertStatus(409)
        ->assertJsonPath('code', 'node_not_trashed');
});

it('rejects a folder of another drive as the destination', function (string $operation): void {
    $drive = DriveTestHelpers::drive();
    $foreign = DriveTestHelpers::drive()->createFolder('Elsewhere');
    $node = $drive->createFolder('Local');

    $run = match ($operation) {
        'create folder' => fn (): Node => $drive->createFolder('Child', $foreign),
        'upload' => fn (): Node => $drive->upload(DriveTestHelpers::streamFor('x'), into: $foreign, originalName: 'x.bin'),
        'move' => fn (): Node => $drive->move($node, $foreign),
        'copy' => fn (): Node => $drive->copy($node, $foreign),
    };

    expect($run)->toThrow(CrossDriveOperationException::class);

    // Over HTTP a foreign node id is not addressable through this drive at all.
    $this->postJson('api/vaulter/nodes/'.$node->getKey().'/move', ['parent_id' => $foreign->getKey()])
        ->assertNotFound();
})->with(['create folder', 'upload', 'move', 'copy']);

it('renders a cross-drive operation as 409 cross_drive_operation', function (): void {
    $drive = DriveTestHelpers::drive();
    $foreign = DriveTestHelpers::drive()->createFolder('Elsewhere');

    $exception = rescue(fn (): Node => $drive->createFolder('Child', $foreign), fn (Throwable $e): Throwable => $e, report: false);

    expect($exception)->toBeInstanceOf(CrossDriveOperationException::class);

    $response = $exception->render(request()->create('api/vaulter/x', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true)['code'])->toBe('cross_drive_operation');
});

it('never renames, moves, trashes or deletes the root', function (string $operation): void {
    $drive = DriveTestHelpers::drive();
    $root = $drive->root();
    $folder = $drive->createFolder('Folder');

    $run = match ($operation) {
        'rename' => fn (): Node => $drive->rename($root, 'Renamed'),
        'move' => fn (): Node => $drive->move($root, $folder),
        'trash' => fn (): Node => $drive->trash($root),
        'force delete' => fn () => $drive->forceDelete($root),
        'copy' => fn (): Node => $drive->copy($root, $folder),
    };

    expect($run)->toThrow(InvalidDestinationException::class, 'drive root');

    $request = match ($operation) {
        'rename' => fn () => $this->patchJson('api/vaulter/nodes/'.$root->getKey(), ['name' => 'Renamed']),
        'move' => fn () => $this->postJson('api/vaulter/nodes/'.$root->getKey().'/move', ['parent_id' => $folder->getKey()]),
        'trash' => fn () => $this->deleteJson('api/vaulter/nodes/'.$root->getKey()),
        'force delete' => fn () => $this->deleteJson('api/vaulter/nodes/'.$root->getKey().'/force'),
        'copy' => fn () => $this->postJson('api/vaulter/nodes/'.$root->getKey().'/copy', ['parent_id' => $folder->getKey()]),
    };

    $request()->assertStatus(409)->assertJsonPath('code', 'invalid_destination');

    expect($root->refresh()->parent_id)->toBeNull()
        ->and($root->trashed_at)->toBeNull()
        ->and($root->name)->toBe('');
})->with(['rename', 'move', 'trash', 'force delete', 'copy']);

it('only force-deletes from the trash', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->createFolder('Live');

    expect(fn () => $drive->forceDelete($node))->toThrow(NodeNotTrashedException::class);

    $this->deleteJson('api/vaulter/nodes/'.$node->getKey().'/force')
        ->assertStatus(409)
        ->assertJsonPath('code', 'node_not_trashed');

    expect(Node::query()->find($node->getKey()))->not->toBeNull();
});

it('refuses to trash a node that is already in the trash', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->trash($drive->createFolder('Twice'));

    expect(fn (): Node => $drive->trash($node))->toThrow(InvalidDestinationException::class, 'is in the trash');
});

it('rejects moving a folder into its own subtree', function (): void {
    $drive = DriveTestHelpers::drive();
    $parent = $drive->createFolder('Parent');
    $child = $drive->createFolder('Child', $parent);

    expect(fn (): Node => $drive->move($parent, $child))->toThrow(NodeCycleException::class)
        ->and(fn (): Node => $drive->move($parent, $parent))->toThrow(NodeCycleException::class);
});

it('resolves a missing destination to the root instead of a parentless node', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->createFolder('Nested', $drive->createFolder('Top'));
    $storageId = $drive->model()->getKey();
    $rootId = $drive->model()->root_node_id;

    $this->postJson('api/vaulter/storages/'.$storageId.'/folders', ['name' => 'Created'])
        ->assertJsonPath('data.parent_id', $rootId);
    $this->postJson('api/vaulter/storages/'.$storageId.'/uploads', ['file' => UploadedFile::fake()->createWithContent('u.txt', 'u')])
        ->assertJsonPath('data.parent_id', $rootId);
    $this->postJson('api/vaulter/nodes/'.$folder->getKey().'/copy', ['parent_id' => null, 'name' => 'Copied'])
        ->assertJsonPath('data.parent_id', $rootId);
    $this->postJson('api/vaulter/nodes/'.$folder->getKey().'/move', ['parent_id' => null])
        ->assertJsonPath('data.parent_id', $rootId);

    expect(Node::withTrashed()->where('drive_id', $storageId)->whereNull('parent_id')->pluck('id')->all())
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
    $a = $drive->createFolder('A');
    $b = $drive->createFolder('B');
    $stale = $drive->createFolder('Node', $a);

    // Another request moves the node to B and trashes it; the caller still holds the old model.
    $fresh = Node::query()->findOrFail($stale->getKey());
    $drive->move($fresh, $b);
    $drive->trash($fresh);

    expect($drive->restore($stale)->parent_id)->toEqual($b->getKey());
});

it('takes the tree lock before any node row lock', function (string $operation): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->createFolder('Target');
    $source = $drive->upload(DriveTestHelpers::streamFor('bytes'), originalName: 'source.bin');
    $queries = [];

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    match ($operation) {
        'create folder' => $drive->createFolder('Child', $folder),
        'upload' => $drive->upload(DriveTestHelpers::streamFor('x'), into: $folder, originalName: 'x.bin'),
        'copy' => $drive->copy($source, $folder),
        'trash' => $drive->trash($folder),
        'rename' => $drive->rename($folder, 'Renamed'),
    };

    $storageLock = array_key_first(array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'select "id" from "v_drives"')));
    $nodeLock = array_key_first(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "v_nodes"') && str_contains($sql, 'order by')));

    // SQLite (like MySQL) implements the tree lock as the storage row.
    expect($storageLock)->not->toBeNull()
        ->and($nodeLock)->not->toBeNull()
        ->and($storageLock)->toBeLessThan($nodeLock);
})->with(['create folder', 'upload', 'copy', 'trash', 'rename']);

it("locks a new version's target before the CAS lock", function (): void {
    $drive = DriveTestHelpers::drive();
    $file = $drive->upload(DriveTestHelpers::streamFor('v1'), originalName: 'f.bin');
    $queries = [];

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $drive->uploadVersion($file, DriveTestHelpers::streamFor('v2 bytes'));

    $nodeLock = array_key_first(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "v_nodes"') && str_contains($sql, 'order by')));
    $blobWork = array_key_first(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"v_blobs"')));

    expect($nodeLock)->not->toBeNull()
        ->and($blobWork)->not->toBeNull()
        ->and($nodeLock)->toBeLessThan($blobWork)
        ->and($drive->model()->refresh()->used_bytes)->toBe(8);
});

it('refuses a new version for a node deleted after it was loaded', function (): void {
    $drive = DriveTestHelpers::drive();
    $file = $drive->upload(DriveTestHelpers::streamFor('v1'), originalName: 'f.bin');
    $stale = Node::query()->findOrFail($file->getKey());

    $drive->trash($file);
    $drive->forceDelete($file);

    expect(fn (): Node => $drive->uploadVersion($stale, DriveTestHelpers::streamFor('v2')))->toThrow(NodeNotFoundException::class);
});

it('purges trash under the tree lock taken before the trashed root row', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->trash($drive->createFolder('Old'));
    $this->travel(400)->days();
    $queries = [];

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('vaulter:purge-trash', ['--days' => 1])->assertSuccessful();

    $treeLock = array_key_first(array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'select "id" from "v_drives"')));
    $rootLock = array_key_first(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "v_nodes"') && str_contains($sql, '"trashed_at" <=') && str_contains($sql, 'limit 1')));

    expect($treeLock)->not->toBeNull()
        ->and($rootLock)->not->toBeNull()
        ->and($treeLock)->toBeLessThan($rootLock);
});
