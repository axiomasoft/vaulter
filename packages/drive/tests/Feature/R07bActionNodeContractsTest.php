<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Drive\Events\NodeForceDeleted;
use Vaulter\Drive\Exceptions\QuotaExceededException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
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
    config()->set('vaulter.dedup.scope', 'tenant');
    $this->actingAs((new TestUser)->forceFill(['id' => 99]));
});

function r07bActStorage(): Storage
{
    return Vaulter::for(DriveTestHelpers::host())->storage();
}

// ---------------------------------------------------------------------------
// UploadFileAction
// ---------------------------------------------------------------------------

// L78 MinusToPlus: quota delta uses subtraction (new - old), not addition.
// A version that SHRINKS the file has delta ≤ 0 and must bypass the quota
// check even when used_bytes == quota_bytes after the prior head.
it('UploadFile L78: shrinking version passes when quota is at capacity (delta is new-old, not new+old)', function (): void {
    $drive = DriveTestHelpers::drive();

    // Upload an 8-byte file to fill storage to exactly 8 bytes.
    $node = $drive->upload(DriveTestHelpers::streamFor('12345678'), originalName: 'f.bin');
    expect($drive->storage()->refresh()->used_bytes)->toBe(8);

    // Clamp quota to current usage — any positive delta would now fail.
    $drive->storage()->forceFill(['quota_bytes' => 8])->save();

    // Upload a 6-byte version (delta = 6 − 8 = −2 ≤ 0) — must NOT throw.
    // MinusToPlus mutant: delta = 6 + 8 = 14 → quota check fires → exception.
    $drive->uploadVersion($node, DriveTestHelpers::streamFor('abcdef'), originalName: 'f.bin');

    // used_bytes drops to 6 (delta = −2).
    expect($drive->storage()->refresh()->used_bytes)->toBe(6);
});

// L80 IfNegated / L81 RemoveMethodCall: assertNameAvailable is called for new
// files (not new versions), so a sibling-name conflict must be detected.
it('UploadFile L80/L81: sibling-name conflict is rejected for a new-file upload', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->upload(DriveTestHelpers::streamFor('a'), originalName: 'dup.bin');

    // Second upload with the same resolved name must throw.
    // IfNegated mutant: skips assertNameAvailable for non-version uploads → no throw.
    expect(fn () => $drive->upload(DriveTestHelpers::streamFor('b'), originalName: 'dup.bin'))
        ->toThrow(NodeNameConflictException::class);
});

// L106 DecrementInteger: delta=0→-1 comparison — a delta of exactly −1 must
// still call adjustUsedBytes; if the condition becomes !== -1, delta=−1 skips
// the adjustment and used_bytes is stuck at the old value.
it('UploadFile L106: used_bytes is decremented when the new version is exactly 1 byte smaller', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->upload(DriveTestHelpers::streamFor('12345'), originalName: 'f.bin'); // 5 bytes
    expect($drive->storage()->refresh()->used_bytes)->toBe(5);

    $drive->uploadVersion($node, DriveTestHelpers::streamFor('1234'), originalName: 'f.bin'); // 4 bytes, delta = −1
    // DecrementInteger mutant: condition becomes $delta !== -1, which is FALSE for delta=−1
    // → adjustUsedBytes is skipped → used_bytes stays at 5. Test fails on the expect below.
    expect($drive->storage()->refresh()->used_bytes)->toBe(4);
});

// L196/L203 CoalesceRemoveLeft: when a new-version upload carries a different
// mimeType, both repointHead calls must use $data->mimeType, not the file's
// stale value ($file->mime_type / $node->mime_type).
it('UploadFile L196/L203: new version with a changed mime type updates node and file mime', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->upload(DriveTestHelpers::streamFor('v1 bytes'), originalName: 'f.bin', mimeType: 'text/plain');

    // Upload a version with a different declared mime.
    $updated = $drive->uploadVersion($node, DriveTestHelpers::streamFor('v2 longer bytes'), originalName: 'f.bin', mimeType: 'application/pdf');

    // CoalesceRemoveLeft mutant: $file->mime_type ('text/plain') is used instead.
    expect($updated->refresh()->mime_type)->toBe('application/pdf');
    expect($updated->file()->first()->mime_type)->toBe('application/pdf');
});

// L234-236 RemoveArrayItem: the version row created by makeVersion must carry
// status='committed', the actor id in created_by, and a non-null committed_at.
it('UploadFile L234-236: committed version has status=committed, correct created_by, and committed_at set', function (): void {
    $this->travelTo(now()->startOfSecond());
    $drive = DriveTestHelpers::drive();

    $node = $drive->upload(DriveTestHelpers::streamFor('data'), originalName: 'doc.txt', actorId: 42);

    $version = $node->currentVersion()->first();
    expect($version->status)->toBe('committed')        // L234
        ->and($version->created_by)->toEqual(42)       // L235
        ->and($version->committed_at)->not->toBeNull() // L236
        ->and($version->committed_at->equalTo(now()))->toBeTrue();
});

// L249 SmallerOrEqualToSmaller / Decrement: delta=0 must be treated as ≤0 and
// skip the quota check — even when storage is already over quota. If the
// boundary shifts to <0, a delta-0 version upload against an over-quota storage
// incorrectly fires the exception.
it('UploadFile L249 SmallerOrEqualToSmaller/Decrement: same-size version passes even when storage is over quota', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->upload(DriveTestHelpers::streamFor('hello'), originalName: 'f.bin'); // 5 bytes; used=5

    // Force storage into over-quota state (used > quota).
    $drive->storage()->forceFill(['quota_bytes' => 4, 'used_bytes' => 5])->save();

    // New version of same 5-byte size → delta = 5 − 5 = 0. Must NOT throw.
    // SmallerOrEqualToSmaller mutant: $delta <= 0 → $delta < 0; delta=0 is not <0
    //   → quota check fires → 5+0 > 4 = true → QuotaExceededException. Test fails.
    $drive->uploadVersion($node, DriveTestHelpers::streamFor('world'), originalName: 'f.bin');

    // used_bytes stays at 5 (delta=0, adjustUsedBytes not called for 0 delta).
    expect($drive->storage()->refresh()->used_bytes)->toBe(5);
});

// L249 Increment: delta=1 must NOT be skipped (1 > 0 is true).  With the
// Increment mutant ($delta <= 1), a 1-byte positive delta is treated as ≤0 and
// the check is bypassed — quota is violated silently.
it('UploadFile L249 Increment: a 1-byte over-quota upload is rejected', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->storage()->forceFill(['quota_bytes' => 5, 'used_bytes' => 5])->save();

    // delta = 1 − 0 = 1 (new file). Must exceed quota: 5+1 > 5 → throw.
    // Increment mutant: $delta <= 1 → skips check → upload silently succeeds.
    expect(fn () => $drive->upload(DriveTestHelpers::streamFor('x'), originalName: 'f.bin'))
        ->toThrow(QuotaExceededException::class);
});

// L312 Increment on probeSize: fstat size+1 causes a false 1-byte quota excess.
// Create a seekable stream of exactly S bytes, used_bytes+S = quota, so the
// upload must pass (used+S ≤ quota). With Increment, probedSize=S+1, delta=S+1,
// used + (S+1) > quota → spurious QuotaExceededException.
it('UploadFile L312 Increment: probeSize probe does not add a phantom byte at the quota boundary', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->storage()->forceFill(['quota_bytes' => 100, 'used_bytes' => 91])->save();

    // Stream of 9 bytes. delta = 9 − 0 = 9. Check: 91+9 = 100, not > 100 → passes.
    // Increment mutant: probedSize=10 → delta=10 → 91+10=101 > 100 → throws. Test fails.
    $node = $drive->upload(DriveTestHelpers::streamFor('123456789'), originalName: 'f.bin');

    expect($node)->toBeInstanceOf(Node::class);
    expect($drive->storage()->refresh()->used_bytes)->toBe(100);
});

// L319 EmptyStringToNotEmpty: the extension() helper must return null when the
// file has no extension. With the mutant, '' === '' becomes '' === ' ', which is
// false, so '' passes through mb_strtolower and an empty string is stored instead.
it('UploadFile L319: a file with no extension stores a null extension, not an empty string', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->upload(DriveTestHelpers::streamFor('bytes'), originalName: 'noextension');

    expect($node->extension)->toBeNull();
});

// L129 RemoveArrayItem: the new file node must carry the actor as owner_id and created_by.
it('UploadFile L129-130: owner_id and created_by are set to actorId on new-file commit', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->upload(DriveTestHelpers::streamFor('data'), originalName: 'f.txt', actorId: 77);

    expect($node->owner_id)->toEqual(77)
        ->and($node->created_by)->toEqual(77);
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
    $source = $drive->upload(DriveTestHelpers::streamFor('x'), originalName: 'tiny.bin');
    $usedBefore = $drive->storage()->refresh()->used_bytes;

    $drive->copy($source, name: 'tiny-copy.bin');

    // Increment mutant: addedBytes > 1 → 1 is not > 1 → skip → used_bytes unchanged.
    expect($drive->storage()->refresh()->used_bytes)->toBe($usedBefore + 1);
});

// L94 FalseToTrue / L95 TrueToFalse:
// is_pinned and inherit_grants are NOT NULL in the schema, so their values on any
// live node are always a boolean (never PHP null). The `?? false` / `?? true`
// fallbacks are therefore dead code and these two mutations are EQUIVALENT.
// Traceability note: CopyMutationContractsTest already asserts is_pinned=true and
// inherit_grants=false when the source has those values set explicitly.
//
// What we CAN assert: the boolean values copy correctly for the common false/true cases.
it('CopyNode L94/L95: is_pinned and inherit_grants propagate correctly from source to copy', function (): void {
    $drive = DriveTestHelpers::drive();
    $source = $drive->upload(DriveTestHelpers::streamFor('data'), originalName: 'f.bin');

    // Source defaults: is_pinned=false, inherit_grants=true (schema defaults).
    $copy = $drive->copy($source, name: 'copy.bin');

    expect((bool) $copy->is_pinned)->toBeFalse()
        ->and((bool) $copy->inherit_grants)->toBeTrue();
});

// L96 RemoveArrayItem: shortcut_target_id must survive the copy.
it('CopyNode L96: shortcut_target_id is preserved on the copied node', function (): void {
    $drive = DriveTestHelpers::drive();
    $target = $drive->createFolder('Target');
    $source = $drive->createFolder('Shortcut');
    $source->forceFill(['shortcut_target_id' => $target->getKey()])->save();

    $copy = $drive->copy($source, name: 'Shortcut Copy');

    // RemoveArrayItem mutant: shortcut_target_id is not inserted → null on copy.
    expect($copy->shortcut_target_id)->toEqual($target->getKey());
});

// L151 RemoveArrayItem in copyFilePayload's version create: status must be 'committed'.
it('CopyNode L151: copied file version has status=committed', function (): void {
    $drive = DriveTestHelpers::drive();
    $source = $drive->upload(DriveTestHelpers::streamFor('bytes'), originalName: 'src.bin');

    $copy = $drive->copy($source, name: 'src-copy.bin');

    $version = $copy->currentVersion()->first();
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
    $version = Node::find($nodeId)->currentVersion()->first();
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
        'file' => UploadedFile::fake()->create('photo.png', 1, 'image/png'),
    ])->assertCreated()->json('data.id');

    // Force node.mime_type to something different so version.mime_type wins.
    Node::find($nodeId)->forceFill(['mime_type' => 'application/octet-stream'])->save();

    app()->instance(StorageDriver::class, new R07bStreamingDriver(app(StorageDriver::class)));

    $response = $this->get('api/vaulter/nodes/'.$nodeId.'/download');
    $response->assertOk();

    // First CoalesceRemoveLeft mutant: node.mime_type used → 'application/octet-stream'.
    expect($response->headers->get('content-type'))->toBe('image/png');
});

// L115 second CoalesceRemoveLeft: node.mime_type is the fallback when version.mime_type is null.
it('DownloadController L115 second CoalesceRemoveLeft: node mime_type is used when version mime_type is null', function (): void {
    $storage = r07bActStorage();

    $nodeId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/uploads', [
        'file' => UploadedFile::fake()->create('photo.png', 1, 'image/png'),
    ])->assertCreated()->json('data.id');

    // Set version.mime_type to null so node.mime_type must be the fallback.
    $version = Node::find($nodeId)->currentVersion()->first();
    $version->forceFill(['mime_type' => null])->save();
    // Ensure node.mime_type is set to the whitelisted value we want to observe.
    Node::find($nodeId)->forceFill(['mime_type' => 'image/png'])->save();

    app()->instance(StorageDriver::class, new R07bStreamingDriver(app(StorageDriver::class)));

    $response = $this->get('api/vaulter/nodes/'.$nodeId.'/download');
    $response->assertOk();

    // Second CoalesceRemoveLeft mutant: removes node.mime_type → 'application/octet-stream' fallback.
    expect($response->headers->get('content-type'))->toBe('image/png');
});

// ---------------------------------------------------------------------------
// ForceDeleteNodeAction
// ---------------------------------------------------------------------------

// L61 GreaterToGreaterOrEqual / Increment: freedBytes > 0 check. A 1-byte file
// must still reduce used_bytes. Increment mutant (> 1) skips for freedBytes=1.
it('ForceDelete L61: force-deleting a 1-byte file fully reclaims the single byte from used_bytes', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->upload(DriveTestHelpers::streamFor('x'), originalName: 'tiny.bin'); // 1 byte
    expect($drive->storage()->refresh()->used_bytes)->toBe(1);

    $drive->forceDelete($node);

    // Increment mutant: freedBytes > 1 → skip adjustUsedBytes → used_bytes stays at 1.
    expect($drive->storage()->refresh()->used_bytes)->toBe(0);
});

// L67 UnwrapArrayValues: nodeIds in NodeForceDeleted must be a list (array_values
// guarantees numeric keys from 0). Without array_values the model-key array from
// modelKeys() is already numerically indexed so this mutation is equivalent —
// noted here for traceability; the assertion documents the list contract.
it('ForceDelete L67: NodeForceDeleted event carries numerically indexed nodeIds list', function (): void {
    Event::fake([NodeForceDeleted::class]);
    $drive = DriveTestHelpers::drive();
    $node = $drive->upload(DriveTestHelpers::streamFor('data'), originalName: 'f.bin');
    $nodeId = $node->getKey();

    $drive->forceDelete($node);

    Event::assertDispatched(NodeForceDeleted::class, function (NodeForceDeleted $e) use ($nodeId): bool {
        // Must be a list: keys are 0, 1, 2, … (array_values guarantee).
        $isListIndexed = array_values($e->nodeIds) === $e->nodeIds;

        return $isListIndexed && in_array($nodeId, $e->nodeIds, strict: false);
    });
});
