<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Support\Config;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The Drive REST surface (M1c-http): a full CRUD cycle through the API plus the
 * cross-cutting guarantees — upload dedup, 422 on a sibling clash, download
 * headers/bytes, swapped-model route binding, and cursor pagination. The slice
 * runs open (authorization is M2), so a bare authenticated actor suffices.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

/**
 * Provision a storage for a fresh host entity and return its id — the routes are
 * storage-scoped, so every test opens against a real storage row.
 */
function driveStorage(): Storage
{
    return Vaulter::for(DriveTestHelpers::host())->storage();
}

it('runs the full CRUD cycle: folder → upload → list → show → rename → move → copy → trash → restore → force', function (): void {
    $storage = driveStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    // create folder
    $folder = $this->postJson($base.'/folders', ['name' => 'Docs'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'folder')
        ->assertJsonPath('data.name', 'Docs')
        ->json('data.id');

    // upload a file into the folder
    $node = $this->postJson($base.'/uploads', [
        'parent_id' => $folder,
        'file' => UploadedFile::fake()->createWithContent('report.txt', 'hello drive'),
    ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'file')
        ->assertJsonPath('data.name', 'report.txt')
        ->assertJsonPath('data.size_bytes', 11)
        ->json('data.id');

    // list children of the folder
    $this->getJson($base.'/nodes?parent_id='.$folder)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $node);

    // show the file node with its breadcrumb
    // breadcrumb is root → Docs → file (the root node is the first crumb).
    $this->getJson('api/vaulter/nodes/'.$node)
        ->assertOk()
        ->assertJsonPath('data.id', $node)
        ->assertJsonPath('data.abilities.download', true)
        ->assertJsonCount(3, 'data.breadcrumb');

    // rename
    $this->patchJson('api/vaulter/nodes/'.$node, ['name' => 'renamed.txt'])
        ->assertOk()
        ->assertJsonPath('data.name', 'renamed.txt');

    // move to the storage root
    $this->postJson('api/vaulter/nodes/'.$node.'/move', ['parent_id' => null])
        ->assertOk()
        ->assertJsonPath('data.parent_id', $storage->refresh()->root_node_id);

    // copy (shares the same blob via dedup)
    $copy = $this->postJson('api/vaulter/nodes/'.$node.'/copy', ['name' => 'copy.txt'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'copy.txt')
        ->json('data.id');

    expect(Blob::query()->count())->toBe(1);

    // trash → restore → force-delete
    $this->deleteJson('api/vaulter/nodes/'.$copy)->assertOk();
    expect(Node::query()->find($copy)->trashed_at)->not->toBeNull();

    $this->postJson('api/vaulter/nodes/'.$copy.'/restore')->assertOk();
    expect(Node::query()->find($copy)->trashed_at)->toBeNull();

    // forceDelete soft-deletes the rows (deleted_at) and frees the bytes; the
    // physical blob is reclaimed later by GC (§2.10).
    $this->deleteJson('api/vaulter/nodes/'.$copy.'/force')->assertNoContent();
    expect(Node::query()->find($copy))->toBeNull()
        ->and(Node::withTrashed()->find($copy)?->deleted_at)->not->toBeNull();
});

it('dedups two identical uploads onto a shared blob', function (): void {
    $storage = driveStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey().'/uploads';

    $this->postJson($base, ['file' => UploadedFile::fake()->createWithContent('a.bin', 'same bytes')])
        ->assertCreated();
    $this->postJson($base, ['file' => UploadedFile::fake()->createWithContent('b.bin', 'same bytes')])
        ->assertCreated();

    expect(Blob::query()->count())->toBe(1)
        ->and(Node::query()->where('kind', 'file')->count())->toBe(2);
});

it('uploads a new version onto an existing file node', function (): void {
    $storage = driveStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey().'/uploads';

    $node = $this->postJson($base, ['file' => UploadedFile::fake()->createWithContent('v.txt', 'v1')])
        ->assertCreated()
        ->json('data.id');

    $firstVersion = Node::query()->find($node)->current_version_id;

    $this->postJson($base, [
        'node_id' => $node,
        'file' => UploadedFile::fake()->createWithContent('v.txt', 'v2 longer'),
    ])
        ->assertCreated()
        ->assertJsonPath('data.id', $node);

    $reloaded = Node::query()->find($node);

    expect($reloaded->current_version_id)->not->toEqual($firstVersion)
        ->and($reloaded->size_bytes)->toBe(9);
});

it('rejects an invalid name with 422', function (): void {
    $storage = driveStorage();

    $this->postJson('api/vaulter/storages/'.$storage->getKey().'/folders', ['name' => 'a/b'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->postJson('api/vaulter/storages/'.$storage->getKey().'/folders', ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('surfaces a sibling-name conflict as a clean 409', function (): void {
    $storage = driveStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey().'/folders';

    $this->postJson($base, ['name' => 'Twin'])->assertCreated();

    $this->postJson($base, ['name' => 'Twin'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'node_name_conflict')
        ->assertJsonValidationErrors('name');
});

it('streams the current version with the right bytes and headers (no temp-url disk)', function (): void {
    // Force the streaming branch: a StorageDriver that mints no signed URL.
    app()->instance(StorageDriver::class, new StreamingOnlyStorageDriver(app(StorageDriver::class)));

    $storage = driveStorage();

    $node = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/uploads', [
        'file' => UploadedFile::fake()->createWithContent('greeting.txt', 'hello bytes'),
    ])->json('data.id');

    $response = $this->get('api/vaulter/nodes/'.$node.'/download');

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/plain')
        ->and($response->headers->get('content-disposition'))->toContain('greeting.txt')
        ->and($response->streamedContent())->toBe('hello bytes');
});

it('streams the bytes through PHP even on a disk that provides a temporary url, never redirecting (P1.2 / F-P1.3-01)', function (): void {
    // The faked local disk DOES provide temporary URLs; the presign-redirect
    // branch was removed (D15 — a presigned URL is a bearer token that bypasses
    // per-request re-authorization and cannot be revoked instantly), so the
    // response is always a streamed 200, never a 302.
    $storage = driveStorage();

    $node = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/uploads', [
        'file' => UploadedFile::fake()->createWithContent('signed.txt', 'never redirected bytes'),
    ])->json('data.id');

    $response = $this->get('api/vaulter/nodes/'.$node.'/download');

    $response->assertOk();
    expect($response->streamedContent())->toBe('never redirected bytes');
});

it('streams a specific historical version', function (): void {
    app()->instance(StorageDriver::class, new StreamingOnlyStorageDriver(app(StorageDriver::class)));

    $storage = driveStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey().'/uploads';

    $node = $this->postJson($base, ['file' => UploadedFile::fake()->createWithContent('h.txt', 'first')])
        ->json('data.id');

    $firstVersionId = Node::query()->find($node)->current_version_id;

    $this->postJson($base, ['node_id' => $node, 'file' => UploadedFile::fake()->createWithContent('h.txt', 'second')])
        ->assertCreated();

    $response = $this->get(sprintf('api/vaulter/nodes/%s/versions/%s/download', $node, $firstVersionId));

    $response->assertOk();
    expect($response->streamedContent())->toBe('first');
});

it('serves a text/html declared mime as octet-stream with nosniff, never the raw declared type (F-P1.3-04, F-P1.11-03)', function (): void {
    // Force the streaming branch — the header hardening sits on the PHP-stream
    // path (the presign-redirect branch is P1.2's scope, not this item's).
    app()->instance(StorageDriver::class, new StreamingOnlyStorageDriver(app(StorageDriver::class)));

    $storage = driveStorage();

    $node = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/uploads', [
        'file' => UploadedFile::fake()->create('evil.txt', 1, 'text/html'),
    ])->json('data.id');

    $response = $this->get('api/vaulter/nodes/'.$node.'/download');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/octet-stream')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

it('serves a whitelisted image/png declared mime as-is, with nosniff still set (F-P1.3-04)', function (): void {
    app()->instance(StorageDriver::class, new StreamingOnlyStorageDriver(app(StorageDriver::class)));

    $storage = driveStorage();

    $node = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/uploads', [
        'file' => UploadedFile::fake()->create('photo.png', 1, 'image/png'),
    ])->json('data.id');

    $response = $this->get('api/vaulter/nodes/'.$node.'/download');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('image/png')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

it('shows storage meta with root node id and quota accounting', function (): void {
    $storage = driveStorage();

    $this->getJson('api/vaulter/storages/'.$storage->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', $storage->getKey())
        ->assertJsonPath('data.root_node_id', $storage->root_node_id)
        ->assertJsonPath('data.used_bytes', 0);
});

it('resolves the swapped Node/Storage models through route-model binding', function (): void {
    config()->set('vaulter.models.node', SwappedNode::class);
    config()->set('vaulter.models.storage', SwappedStorage::class);

    $storage = driveStorage();

    expect($storage)->toBeInstanceOf(SwappedStorage::class);

    $node = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/folders', ['name' => 'Bound'])
        ->assertCreated()
        ->json('data.id');

    // The bound parameter resolves through the swapped model — a successful show
    // proves the Route::bind honoured config('vaulter.models.*').
    $this->getJson('api/vaulter/nodes/'.$node)
        ->assertOk()
        ->assertJsonPath('data.name', 'Bound');
});

it('cursor-paginates the folder listing', function (): void {
    $storage = driveStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson($base.'/folders', ['name' => 'F'.$i])->assertCreated();
    }

    $first = $this->getJson($base.'/nodes?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.path', fn (mixed $path): bool => is_string($path));

    $nextCursor = $first->json('meta.next_cursor');

    expect($nextCursor)->not->toBeNull();

    $this->getJson($base.'/nodes?per_page=2&cursor='.$nextCursor)
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('resolves a listing page of sibling nodes with O(1) storage queries, not O(N) (F-P4.2-03)', function (): void {
    $storage = driveStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();
    $siblingCount = 5;

    for ($i = 0; $i < $siblingCount; $i++) {
        $this->postJson($base.'/folders', ['name' => 'Sib'.$i])->assertCreated();
    }

    $storageTable = Config::table('storages');
    $storageQueries = 0;

    DB::listen(function (object $query) use (&$storageQueries, $storageTable): void {
        if (str_contains($query->sql, $storageTable)) {
            $storageQueries++;
        }
    });

    $this->getJson($base.'/nodes?per_page='.$siblingCount)
        ->assertOk()
        ->assertJsonCount($siblingCount, 'data');

    // Every one of the N sibling nodes on the page belongs to the SAME storage —
    // `AccessResolver::storageOf()` is called once per node (via
    // `NodeResource::abilities()`), but the scoped-singleton `StorageReadRepository`
    // memoizes by id, so the whole page issues AT MOST 2 storage-table hits (the
    // Route::bind model resolution for {storage} in the URL, which queries the
    // model directly and is NOT the repository under test, plus one repository
    // cache-miss) — never one per node. Before the P1.3 fix this counted
    // (1 + $siblingCount).
    expect($storageQueries)->toBeLessThanOrEqual(2)
        ->and($storageQueries)->toBeLessThan($siblingCount);
});

/**
 * Swapped models for the route-model-binding test — subclasses the host may point
 * config('vaulter.models.*') at. They inherit everything; only their class
 * identity matters.
 */
class SwappedNode extends Node {}

class SwappedStorage extends Storage {}

/**
 * A StorageDriver decorator that mints no signed URL — it forces the download
 * controller down the streaming branch so the streamed bytes/headers can be
 * asserted directly. Every other call delegates to the real driver.
 */
final class StreamingOnlyStorageDriver implements StorageDriver
{
    public function __construct(private readonly StorageDriver $inner) {}

    public function disk(?string $disk = null): Filesystem
    {
        return $this->inner->disk($disk);
    }

    public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool
    {
        return $this->inner->put($key, $stream, $disk, $ifNoneMatch, $options);
    }

    public function get(string $key, ?string $disk = null): mixed
    {
        return $this->inner->get($key, $disk);
    }

    public function exists(string $key, ?string $disk = null): bool
    {
        return $this->inner->exists($key, $disk);
    }

    public function delete(string $key, ?string $disk = null): void
    {
        $this->inner->delete($key, $disk);
    }

    public function temporaryUrl(string $key, DateTimeInterface $expiresAt, ?string $disk = null): ?string
    {
        return null;
    }
}
