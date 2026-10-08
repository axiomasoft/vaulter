<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\RevisionOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\Nodes\NodePurged;
use Vaulter\Drive\Exceptions\Quota\QuotaExceededException;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Exceptions\Nodes\UnsupportedNodeKindException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * Streaming-only StorageDriver stub (no presigned URLs) — scoped to this file
 * to avoid colliding with R07StreamingOnlyStorageDriver or StreamingOnlyStorageDriver
 * in sibling test files.
 */
final class R07bStreamingDriver implements StorageDriver
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
        return null; // force PHP streaming path
    }
}

beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    $this->actingAs((new TestUser)->forceFill(['id' => 99]));
});

function r07bActStorage(): Drive
{
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

// ---------------------------------------------------------------------------
// UploadFileAction
// ---------------------------------------------------------------------------

// L78: a new version is a new retained revision (PLAN2.D48) — it is charged with its
// full size, even when it is smaller than the head, so at capacity it is refused and
// with room for its full size it passes.
it('UploadFile L78: a smaller version is charged in full and refused at capacity', function (): void {
    $drive = DriveTestHelpers::drive();

    // Upload an 8-byte file to fill storage to exactly 8 bytes.
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('12345678'), 'f.bin'));
    expect($drive->model()->refresh()->used_bytes)->toBe(8);

    $drive->model()->forceFill(['quota_bytes' => 13])->save();
    expect(fn () => $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('abcdef'), 'f.bin')))
        ->toThrow(QuotaExceededException::class);
    expect($drive->model()->refresh()->used_bytes)->toBe(8);

    $drive->model()->forceFill(['quota_bytes' => 14])->save();
    $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('abcdef'), 'f.bin'));
    expect($drive->model()->refresh()->used_bytes)->toBe(14);
});

// L80 IfNegated / L81 RemoveMethodCall: assertNameAvailable is called for new
// files (not new versions), so a sibling-name conflict must be detected.
it('UploadFile L80/L81: sibling-name conflict is rejected for a new-file upload', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('a'), 'dup.bin'));

    // Second upload with the same resolved name must throw.
    // IfNegated mutant: skips assertNameAvailable for non-version uploads → no throw.
    expect(fn () => $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('b'), 'dup.bin')))
        ->toThrow(NodeNameConflictException::class);
});

// L106: the prior head stays retained, so a version 1 byte smaller still adds its
// full size (PLAN2.D48).
it('UploadFile L106: used_bytes grows by the full size of a version 1 byte smaller', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('12345'), 'f.bin')); // 5 bytes
    expect($drive->model()->refresh()->used_bytes)->toBe(5);

    $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('1234'), 'f.bin')); // 4 bytes
    expect($drive->model()->refresh()->used_bytes)->toBe(9);
});

// L196/L203 CoalesceRemoveLeft: when a new-version upload carries a different
// mimeType, both repointHead calls must use $data->mimeType, not the file's
// stale value ($file->mime_type / $node->mime_type).
it('UploadFile L196/L203: new version with a changed mime type updates node and file mime', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('v1 bytes'), 'f.bin'), new UploadOptions(declaredMimeType: 'text/plain'));

    // Upload a version with a different declared mime.
    $image = UploadedFile::fake()->image('revision.png', 1, 1);
    $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor(file_get_contents($image->getRealPath())), 'f.bin'), new RevisionOptions(declaredMimeType: 'image/png'));
    $updated = $node->refresh();

    // CoalesceRemoveLeft mutant: $file->mime_type ('text/plain') is used instead.
    expect($updated->refresh()->mime_type)->toBe('image/png');
    expect($updated->file()->first()->mime_type)->toBe('image/png');
});

// L234-236 RemoveArrayItem: the version row created by makeVersion must carry
// status='committed', the actor id in created_by, and a non-null committed_at.
it('UploadFile L234-236: committed version has status=committed, correct created_by, and committed_at set', function (): void {
    $this->travelTo(now()->startOfSecond());
    $drive = DriveTestHelpers::drive();

    $node = $drive->actingAs(DriveTestHelpers::actor($drive, 42))->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('data'), 'doc.txt'));

    $version = $node->file()->firstOrFail()->currentRevision()->first();
    expect($version->status)->toBe('committed')        // L234
        ->and($version->created_by)->toEqual(42)       // L235
        ->and($version->committed_at)->not->toBeNull() // L236
        ->and($version->committed_at->equalTo(now()))->toBeTrue();
});

// L249: a drive over its quota keeps reading and deleting, but any growth — a
// same-size version included — is refused (PLAN2.D48).
it('UploadFile L249: a same-size version is refused while the drive is over its quota', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('hello'), 'f.bin')); // 5 bytes; used=5

    // Force storage into over-quota state (used > quota).
    $drive->model()->forceFill(['quota_bytes' => 4, 'used_bytes' => 5])->save();

    expect(fn () => $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('world'), 'f.bin')))
        ->toThrow(QuotaExceededException::class);
    expect($drive->model()->refresh()->used_bytes)->toBe(5);
});

// L249 Increment: delta=1 must NOT be skipped (1 > 0 is true).  With the
// Increment mutant ($delta <= 1), a 1-byte positive delta is treated as ≤0 and
// the check is bypassed — quota is violated silently.
it('UploadFile L249 Increment: a 1-byte over-quota upload is rejected', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->model()->forceFill(['quota_bytes' => 5, 'used_bytes' => 5])->save();

    // delta = 1 − 0 = 1 (new file). Must exceed quota: 5+1 > 5 → throw.
    // Increment mutant: $delta <= 1 → skips check → upload silently succeeds.
    expect(fn () => $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('x'), 'f.bin')))
        ->toThrow(QuotaExceededException::class);
});

// L312 Increment on probeSize: fstat size+1 causes a false 1-byte quota excess.
// Create a seekable stream of exactly S bytes, used_bytes+S = quota, so the
// upload must pass (used+S ≤ quota). With Increment, probedSize=S+1, delta=S+1,
// used + (S+1) > quota → spurious QuotaExceededException.
it('UploadFile L312 Increment: probeSize probe does not add a phantom byte at the quota boundary', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->model()->forceFill(['quota_bytes' => 100, 'used_bytes' => 91])->save();

    // Stream of 9 bytes. delta = 9 − 0 = 9. Check: 91+9 = 100, not > 100 → passes.
    // Increment mutant: probedSize=10 → delta=10 → 91+10=101 > 100 → throws. Test fails.
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('123456789'), 'f.bin'));

    expect($node)->toBeInstanceOf(Node::class);
    expect($drive->model()->refresh()->used_bytes)->toBe(100);
});

// L319 EmptyStringToNotEmpty: the extension() helper must return null when the
// file has no extension. With the mutant, '' === '' becomes '' === ' ', which is
// false, so '' passes through mb_strtolower and an empty string is stored instead.
it('UploadFile L319: a file with no extension stores a null extension, not an empty string', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('bytes'), 'noextension'));

    expect($node->extension)->toBeNull();
});

// L129 RemoveArrayItem: the new file node records the actor as created_by; nodes have
// no implicit owner (PLAN2.D46/D53), so no owner_id is written.
it('UploadFile L129-130: created_by is set to actorId on new-file commit and no node owner exists', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->actingAs(DriveTestHelpers::actor($drive, 77))->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('data'), 'f.txt'));

    expect($node->created_by)->toEqual(77)
        ->and(array_key_exists('owner_id', $node->refresh()->getAttributes()))->toBeFalse();
});

// ---------------------------------------------------------------------------
// NodeController
// ---------------------------------------------------------------------------

// L151 IfNegated / IdenticalToNotIdentical / L152 RemoveMethodCall:
// the show() handler adds children only to Folder nodes, never to File nodes.
it('NodeController L151/L152: show folder response includes children; show file does not', function (): void {
    $storage = r07bActStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $folderId = $this->postJson($base.'/folders', ['name' => 'Parent'])->assertCreated()->json('data.id');
    $childId = $this->postJson($base.'/folders', ['name' => 'Child', 'parent_id' => $folderId])
        ->assertCreated()->json('data.id');

    // Folder show: children must be present.
    // L152 RemoveMethodCall mutant: withChildren() skipped → key absent.
    $folderResponse = $this->getJson('api/vaulter/nodes/'.$folderId)->assertOk();
    expect($folderResponse->json('data.children'))->not->toBeNull()
        ->and($folderResponse->json('data.children'))->toBeArray()
        ->and(count($folderResponse->json('data.children')))->toBe(1);

    // File show: children must NOT appear.
    // L151 IfNegated mutant: attaches children to files too.
    $fileId = $this->postJson($base.'/uploads', [
        'parent_id' => $folderId,
        'file' => UploadedFile::fake()->createWithContent('f.txt', 'hi'),
    ])->assertCreated()->json('data.id');

    $fileResponse = $this->getJson('api/vaulter/nodes/'.$fileId)->assertOk();
    expect($fileResponse->json('data.children'))->toBeNull();
});

// L374 BooleanOrToBooleanAnd: findNodeInStorage must 404 when the node exists
// but belongs to a different storage (cross-storage isolation).
it('NodeController L374: cross-storage node lookup 404s', function (): void {
    $storageA = r07bActStorage();
    $storageB = r07bActStorage();

    $folderId = $this->postJson('api/vaulter/storages/'.$storageA->getKey().'/folders', ['name' => 'Secret'])
        ->assertCreated()->json('data.id');

    // Try to list children of storageA's folder through storageB's route.
    // BooleanOrToBooleanAnd mutant: only checks !$node instanceof Node, ignores storage mismatch.
    $this->getJson('api/vaulter/storages/'.$storageB->getKey().'/nodes?parent_id='.$folderId)
        ->assertNotFound();
});

// L383/L385 RemoveIntegerCast / Decrement / Increment:
// The shared endpoint uses LengthAwarePaginator and exposes meta.per_page, letting
// us assert the EXACT clamped value rather than a result-count proxy.
// Route: GET api/vaulter/shared (fixed segment, gated on features.grants = true by default).
it('NodeController L383/L385: per_page is clamped to exactly 1 at the lower bound and 200 at the upper bound', function (): void {
    // Ensure the grants feature is enabled so the shared route is registered.
    config()->set('vaulter.features.grants', true);

    // per_page=0 → max(1, ...) → 1 exactly.
    // Decrement on 1: max(0, ...) → 0 stored; Increment: max(2, ...) → 2.
    $this->getJson('api/vaulter/shared?per_page=0')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 1);

    $this->getJson('api/vaulter/shared?per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 1);

    // per_page=200 → min(..., 200) → 200 exactly.
    // Decrement on 200: min(..., 199) → 199 — caught here.
    $this->getJson('api/vaulter/shared?per_page=200')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 200);

    // Requesting over the cap: must be clamped to 200, not 201.
    $this->getJson('api/vaulter/shared?per_page=201')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 200);
});

// ---------------------------------------------------------------------------
// CopyNodeAction
// ---------------------------------------------------------------------------

// L70 GreaterToGreaterOrEqual / Increment: addedBytes > 0 check. A 1-byte file
// copy must still increment used_bytes (Increment mutant: > 1 skips the call).
it('CopyNode L70: copying a 1-byte file increments used_bytes by exactly 1', function (): void {
    $drive = DriveTestHelpers::drive();
    $source = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('x'), 'tiny.bin'));
    $usedBefore = $drive->model()->refresh()->used_bytes;

    $drive->nodes()->copy($source, destination: null, options: new CopyOptions(name: 'tiny-copy.bin'));

    // Increment mutant: addedBytes > 1 → 1 is not > 1 → skip → used_bytes unchanged.
    expect($drive->model()->refresh()->used_bytes)->toBe($usedBefore + 1);
});

// inherit_grants is NOT NULL in the schema, so a live node always carries a boolean.
// The copy keeps the source's value; properties copy with it (PLAN2.D41).
it('CopyNode: inherit_grants and properties propagate from source to copy', function (): void {
    $drive = DriveTestHelpers::drive();
    $source = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('data'), 'f.bin'));

    $copy = $drive->nodes()->copy($source, destination: null, options: new CopyOptions(name: 'copy.bin'));

    expect((bool) $copy->inherit_grants)->toBeTrue()
        ->and($copy->properties)->toBeNull();
});

// Shortcut payload copy is deliberately unavailable until its full lifecycle exists.
it('CopyNode rejects a real shortcut before inserting a copy or charging quota', function (): void {
    $drive = DriveTestHelpers::drive();
    $target = $drive->folders()->create('Target');
    $source = $drive->folders()->create('Shortcut');
    $source->forceFill(['kind' => NodeKind::Shortcut, 'shortcut_target_id' => $target->getKey()])->save();
    $db = app(VaulterDatabase::class)->connection();
    $state = static fn (): array => [
        $db->table('v_nodes')->count(),
        $db->table('v_file_revisions')->count(),
        $db->table('v_file_revision_blobs')->count(),
        $drive->model()->refresh()->used_bytes,
    ];
    $before = $state();

    expect(fn () => $drive->nodes()->copy($source, options: new CopyOptions(name: 'Shortcut Copy')))
        ->toThrow(UnsupportedNodeKindException::class)
        ->and($state())->toBe($before)
        ->and($source->refresh()->shortcut_target_id)->toEqual($target->getKey());
});

// L151 RemoveArrayItem in copyFilePayload's version create: status must be 'committed'.
it('CopyNode L151: copied file version has status=committed', function (): void {
    $drive = DriveTestHelpers::drive();
    $source = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('bytes'), 'src.bin'));

    $copy = $drive->nodes()->copy($source, destination: null, options: new CopyOptions(name: 'src-copy.bin'));

    $version = $copy->file()->firstOrFail()->currentRevision()->first();
    // RemoveArrayItem mutant: 'status' key missing → DB default (likely null or empty).
    expect($version->status)->toBe('committed');
});

// ---------------------------------------------------------------------------
// DownloadController
// ---------------------------------------------------------------------------

// L114 TernaryNegated / NotIdenticalToIdentical: original_name is used as the
// download filename when it is non-empty; the node-name must NOT be used instead.
it('DownloadController L114: non-empty original_name is used as filename, not node name', function (): void {
    $storage = r07bActStorage();

    // Upload so the original file name ('original.txt') differs from the stored name ('stored.txt').
    $nodeId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/uploads', [
        'file' => UploadedFile::fake()->createWithContent('original.txt', 'payload'),
        'name' => 'stored.txt',
    ])->assertCreated()->json('data.id');

    app()->instance(StorageDriver::class, new R07bStreamingDriver(app(StorageDriver::class)));

    $response = $this->get('api/vaulter/nodes/'.$nodeId.'/download');
    $response->assertOk();

    // TernaryNegated mutant: condition is reversed → node name 'stored.txt' used instead.
    expect($response->headers->get('content-disposition'))->toContain('original.txt');
});

// L114 EmptyStringToNotEmpty: when original_name is '', the fallback must be node->name.
it('DownloadController L114: empty original_name falls back to node name in the filename', function (): void {
    $storage = r07bActStorage();

    $nodeId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/uploads', [
        'file' => UploadedFile::fake()->createWithContent('report.txt', 'data'),
    ])->assertCreated()->json('data.id');

    // Force original_name to '' on the current version so the fallback branch is exercised.
    $version = Node::find($nodeId)->file()->firstOrFail()->currentRevision()->first();
    $version->forceFill(['original_name' => ''])->save();

    app()->instance(StorageDriver::class, new R07bStreamingDriver(app(StorageDriver::class)));

    $response = $this->get('api/vaulter/nodes/'.$nodeId.'/download');
    $response->assertOk();

    // EmptyStringToNotEmpty mutant: '' === ' ' is false → '' returned as filename.
    // Original: '' === '' → true → uses node name 'report.txt'.
    expect($response->headers->get('content-disposition'))->toContain('report.txt');
});

// L115 first CoalesceRemoveLeft: version.mime_type takes precedence over node.mime_type.
// When version.mime_type is non-null, it must be used for Content-Type, not node.mime_type.
it('DownloadController L115 first CoalesceRemoveLeft: version mime_type wins over node mime_type', function (): void {
    $storage = r07bActStorage();

    // Upload with image/png (whitelisted, served as-is).
    $nodeId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/uploads', [
        'file' => UploadedFile::fake()->image('photo.png', 1, 1),
    ])->assertCreated()->json('data.id');

    // Force node.mime_type to something different so version.mime_type wins.
    Node::find($nodeId)->forceFill(['mime_type' => 'application/octet-stream'])->save();

    app()->instance(StorageDriver::class, new R07bStreamingDriver(app(StorageDriver::class)));

    $response = $this->get('api/vaulter/nodes/'.$nodeId.'/download');
    $response->assertOk();

    // First CoalesceRemoveLeft mutant: node.mime_type used → 'application/octet-stream'.
    expect($response->headers->get('content-type'))->toBe('image/png');
});

// Delivery takes MIME from the committed immutable revision, with a safe null fallback.
it('DownloadController uses octet-stream when revision MIME is null despite node MIME', function (): void {
    $storage = r07bActStorage();
    $nodeId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/uploads', [
        'file' => UploadedFile::fake()->image('photo.png', 1, 1),
    ])->assertCreated()->json('data.id');
    $version = Node::find($nodeId)->file()->firstOrFail()->currentRevision()->first();
    $version->forceFill(['mime_type' => null])->save();
    Node::find($nodeId)->forceFill(['mime_type' => 'image/png'])->save();
    app()->instance(StorageDriver::class, new R07bStreamingDriver(app(StorageDriver::class)));

    $response = $this->get('api/vaulter/nodes/'.$nodeId.'/download');
    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/octet-stream');
});

// ---------------------------------------------------------------------------
// ForceDeleteNodeAction
// ---------------------------------------------------------------------------

// L61 GreaterToGreaterOrEqual / Increment: freedBytes > 0 check. A 1-byte file
// must still reduce used_bytes. Increment mutant (> 1) skips for freedBytes=1.
it('ForceDelete L61: force-deleting a 1-byte file fully reclaims the single byte from used_bytes', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('x'), 'tiny.bin')); // 1 byte
    expect($drive->model()->refresh()->used_bytes)->toBe(1);

    $drive->nodes()->trash($node);

    $drive->trash()->purge($node);

    // Increment mutant: freedBytes > 1 → skip adjustUsedBytes → used_bytes stays at 1.
    expect($drive->model()->refresh()->used_bytes)->toBe(0);
});

// L67 UnwrapArrayValues: nodeIds in NodeForceDeleted must be a list (array_values
// guarantees numeric keys from 0). Without array_values the model-key array from
// modelKeys() is already numerically indexed so this mutation is equivalent —
// noted here for traceability; the assertion documents the list contract.
it('ForceDelete L67: NodeForceDeleted event carries numerically indexed nodeIds list', function (): void {
    Event::fake([NodePurged::class]);
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('data'), 'f.bin'));
    $nodeId = $node->getKey();

    $drive->nodes()->trash($node);

    $drive->trash()->purge($node);

    Event::assertDispatched(NodePurged::class, function (NodePurged $e) use ($nodeId): bool {
        // Must be a list: keys are 0, 1, 2, … (array_values guarantee).
        $isListIndexed = array_values($e->nodeIds) === $e->nodeIds;

        return $isListIndexed && in_array($nodeId, $e->nodeIds, strict: false);
    });
});
