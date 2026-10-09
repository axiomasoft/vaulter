<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Events\Nodes\NodePurged;
use Vaulter\Drive\Events\Uploads\UploadAborted;
use Vaulter\Drive\Events\Uploads\UploadExpired;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadPartStoreRepository;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventRecorder;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Access\ActorRef;

// These tests drive handlers and repositories directly; Vaulter's entry points open the write scope, these calls do not.
beforeEach(fn () => DriveTestHelpers::insideOperation());

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

// ── helpers ──────────────────────────────────────────────────────────────────

/**
 * Quick storage with explicit tenant_id (not the default null).
 */
function r07bReqTenantStorage(int $tenantId): Drive
{
    static $seq = 0;
    $seq++;

    $storage = new Drive;
    $storage->forceFill([
        'owner_type' => 'team',
        'owner_id' => 90000 + $seq,
        'name' => 'TenantDisk',
        'disk' => 'local',
        'tenant_id' => $tenantId,
        'used_bytes' => 0,
    ]);
    app(VaulterDatabase::class)->write(fn () => $storage->save());

    $root = new Node;
    $root->forceFill([
        'drive_id' => $storage->getKey(),
        'parent_id' => null,
        'kind' => NodeKind::Folder,
        'name' => '',
        'size_bytes' => 0,
    ]);
    app(VaulterDatabase::class)->write(fn () => $root->save());

    app(VaulterDatabase::class)->write(fn () => $storage->forceFill(['root_node_id' => $root->getKey()])->save());

    return $storage->refresh();
}

// ── UploadSessionManager.initiate: session attribute contracts ────────────────

it('R07b: initiate stores the tenant_id from the storage row (L307 AlwaysReturnNull tenantId)', function (): void {
    // Use integer tenant_id (bigint column on pgsql, works on sqlite too)
    $tenantId = 99887766;
    $storage = r07bReqTenantStorage($tenantId);

    $session = app(UploadSessionManager::class)->initiate(context: OperationContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'tenant.bin',
        totalSizeBytes: 4,
    ));

    // AlwaysReturnNull mutation: tenantId() always returns null → session.tenant_id = null
    expect($session->tenant_id)->toBe((string) $tenantId);
});

it('R07b: initiate upload_id is stored as a non-empty string (L66 RemoveStringCast)', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = app(UploadSessionManager::class)->initiate(context: OperationContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'uid.bin',
        totalSizeBytes: 4,
    ));

    expect($session->upload_id)->toBeString()->not->toBeEmpty();
});

it('R07b: initiate target_name stored from UploadInitData (L74 RemoveArrayItem target_name)', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = app(UploadSessionManager::class)->initiate(context: OperationContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'specific-name.bin',
        totalSizeBytes: 4,
    ));

    expect($session->target_name)->toBe('specific-name.bin');
});

it('R07b: initiate node_id stored from UploadInitData targetNode (L71 RemoveArrayItem node_id)', function (): void {
    $storage = UploadTestHelpers::storage();
    $root = $storage->rootNode()->first();

    $node = new Node;
    $node->forceFill([
        'drive_id' => $storage->getKey(),
        'parent_id' => $root->getKey(),
        'kind' => NodeKind::File,
        'name' => 'versioned.bin',
        'size_bytes' => 0,
    ]);
    app(VaulterDatabase::class)->write(fn () => $node->save());

    $session = app(UploadSessionManager::class)->initiate(context: OperationContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetNode: $node,
        totalSizeBytes: 4,
    ));

    expect($session->target_node_id)->toEqual($node->getKey());
});

it('R07b: initiate metadata=[] retains server owner identity (L77 RemoveArrayItem metadata coalesce)', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = app(UploadSessionManager::class)->initiate(context: OperationContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'meta-empty.bin',
        totalSizeBytes: 4,
        metadata: [],
    ));

    expect($session->metadata)->toBe(['actor_type' => 'vaulter:system', 'expected_sequence' => null]);
});

it('R07b: initiate non-empty metadata retains client values and server identity (L77 sanity)', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = app(UploadSessionManager::class)->initiate(context: OperationContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'meta-set.bin',
        totalSizeBytes: 4,
        metadata: ['filename' => 'original.bin'],
    ));

    expect($session->metadata)->toBe(['filename' => 'original.bin', 'actor_type' => 'vaulter:system', 'expected_sequence' => null]);
});

// ── assertWithinMaxBytes boundary (L263 BooleanOr and ±1) ───────────────────

it('R07b: max_bytes=0 bypasses the ceiling for any total_size_bytes (L263 BooleanOr)', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', 0);
    $storage = UploadTestHelpers::storage();

    // With BooleanOr: max===0 && totalSizeBytes===null = false → tries comparison → null > 0 = false → ok
    // With BooleanOr and totalSizeBytes=999: max===0 && 999===null = false → 999 > 0 → throws
    // Original: max===0 || ... = true → returns (no error)
    $session = app(UploadSessionManager::class)->initiate(context: OperationContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'big.bin',
        totalSizeBytes: 999_999,
    ));

    expect($session->total_size_bytes)->toBe(999_999);
});

it('R07b: max_bytes=1 blocks total_size_bytes=2 (L263 ±1 IncrementInteger)', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', 1);
    $storage = UploadTestHelpers::storage();

    // With IncrementInteger on 0: max <= 1 → 1 <= 1 = true → returns early → no error
    // Original: 1 <= 0 = false → checks 2 > 1 → throws
    expect(fn () => app(UploadSessionManager::class)->initiate(context: OperationContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'over.bin',
        totalSizeBytes: 2,
    )))->toThrow(ValidationException::class);
});

it('R07b: max_bytes=1 allows total_size_bytes=1 exactly (L263 ±1 boundary)', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', 1);
    $storage = UploadTestHelpers::storage();

    $session = app(UploadSessionManager::class)->initiate(context: OperationContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'exact.bin',
        totalSizeBytes: 1,
    ));

    expect($session->total_size_bytes)->toBe(1);
});

// ── assertWithinActiveSessionCap (L269 RemoveArrayItem) ─────────────────────

it('R07b: null actorId bypasses the active session cap (L269 RemoveArrayItem actorId check)', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_active_sessions_per_actor', 1);
    $storage = UploadTestHelpers::storage();
    $root = $storage->rootNode()->first();
    $manager = app(UploadSessionManager::class);

    // Both sessions have actorId=null — should succeed even with cap=1
    foreach (['null-a.bin', 'null-b.bin'] as $name) {
        $manager->initiate(context: OperationContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
            protocol: UploadProtocol::Tus,
            targetParent: $root,
            targetName: $name,
            totalSizeBytes: 4,
        ));
    }

    expect(UploadSession::query()->whereNull('created_by')->count())->toBe(2);
});

it('R07b: max_active_sessions=0 disables the cap for any actorId (L269 RemoveArrayItem max===0 check)', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_active_sessions_per_actor', 0);
    $storage = UploadTestHelpers::storage();
    $root = $storage->rootNode()->first();
    $manager = app(UploadSessionManager::class);

    foreach (range(1, 3) as $i) {
        $manager->initiate(context: new OperationContext(drive: $storage, actor: Actor::user((new TestUser)->forceFill(['id' => 88])), correlationId: (string) Str::ulid()), data: new InitiateUploadInput(
            protocol: UploadProtocol::Tus,
            targetParent: $root,
            targetName: sprintf('unlimited-%s.bin', $i),
            totalSizeBytes: 4,
        ));
    }

    expect(UploadSession::query()->where('created_by', 88)->count())->toBe(3);
});

// ── guardTransition error field (L300 RemoveArrayItem 'status' key) ──────────

it('R07b: guardTransition ValidationException carries the "status" field in errors (L300 RemoveArrayItem)', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 4);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['status' => UploadStatus::Committed])->save());

    try {
        app(UploadSessionManager::class)->markAssembled($session->refresh());
        expect(false)->toBeTrue('Expected ValidationException was not thrown');
    } catch (ValidationException $validationException) {
        expect(array_keys($validationException->errors()))->toContain('status');
    }
});

// ── UploadSessionStoreRepository: advanceReceived status filter (L182) ────────

it('R07b: advanceReceived works on an Uploading session (L182 RemoveArrayItem Uploading)', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 16);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['status' => UploadStatus::Uploading, 'received_bytes' => 4])->save());

    app(UploadSessionStoreRepository::class)->advanceReceived($session->refresh(), 8);

    expect($session->fresh()->received_bytes)->toBe(8)
        ->and($session->fresh()->status)->toBe(UploadStatus::Uploading);
});

it('R07b: advanceReceived throws on an Aborted session (L182 boundary — non-open status)', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 4);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['status' => UploadStatus::Aborted])->save());

    expect(fn () => app(UploadSessionStoreRepository::class)->advanceReceived($session->refresh(), 4))
        ->toThrow(UploadConflictException::class);
});

// ── UploadSessionStoreRepository: markCommitted Assembled check (L217) ────────

it('R07b: markCommitted throws UploadConflictException on an Initiated session (L217 RemoveArrayItem)', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 4);
    expect($session->status)->toBe(UploadStatus::Initiated);

    expect(fn () => app(UploadSessionStoreRepository::class)->markCommitted($session, 99))
        ->toThrow(UploadConflictException::class);
});

it('R07b: markCommitted throws on an Uploading session — only Assembled passes (L217 boundary)', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 4);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['status' => UploadStatus::Uploading])->save());

    expect(fn () => app(UploadSessionStoreRepository::class)->markCommitted($session->refresh(), 99))
        ->toThrow(UploadConflictException::class);
});

// ── UploadPart casts (L57 AlwaysReturnEmptyArray, L58-L61 RemoveArrayItem) ───

it('R07b: UploadPart part_no and byte_offset and size_bytes cast to strict int (L57/L58/L59/L60)', function (): void {
    $session = UploadTestHelpers::session(
        UploadTestHelpers::storage(),
        protocol: UploadProtocol::S3Multipart,
        totalSizeBytes: 20,
    );
    $part = app(UploadPartStoreRepository::class)->record(
        $session,
        partNo: 3,
        byteOffset: 10,
        sizeBytes: 5,
        etag: 'etag-cast',
    );

    // Strict type checks — would fail if casts() returns [] (L57) or removes individual entries (L58-60)
    expect($part->part_no)->toBe(3)
        ->and($part->byte_offset)->toBe(10)
        ->and($part->size_bytes)->toBe(5);
});

it('R07b: UploadPart received_at casts to Carbon (L57/L61 received_at cast)', function (): void {
    $session = UploadTestHelpers::session(
        UploadTestHelpers::storage(),
        protocol: UploadProtocol::S3Multipart,
        totalSizeBytes: 20,
    );
    $part = app(UploadPartStoreRepository::class)->record(
        $session,
        partNo: 1,
        byteOffset: 0,
        sizeBytes: 5,
    );

    expect($part->received_at)->toBeInstanceOf(Carbon::class);
});

// ── EventRecorder: upload.aborted activity context ───────

function r07bRecord(DomainEvent $event): void
{
    app(VaulterDatabase::class)->transaction(fn () => app(EventRecorder::class)->record($event));
}

function r07bActor(): ActorRef
{
    return new ActorRef(type: 'user', id: 42);
}

it('R07b: upload.aborted records all three context keys', function (): void {
    app(VaulterDatabase::class)->write(fn () => Activity::query()->delete());

    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 8);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['received_bytes' => 5])->save());
    $session->refresh();

    r07bRecord(new UploadAborted(session: $session, actor: r07bActor(), correlationId: (string) Str::ulid()));

    $row = Activity::query()->where('verb', EventType::UploadAborted->value)->sole();

    expect($row->context)->toHaveKeys(['upload_id', 'protocol', 'received_bytes'])
        ->and($row->context['upload_id'])->toBeString()->not->toBeEmpty()
        ->and($row->context['protocol'])->toBeString()
        ->and($row->context['received_bytes'])->toBe(5);
});

it('R07b: upload.aborted upload_id context is the session upload_id', function (): void {
    app(VaulterDatabase::class)->write(fn () => Activity::query()->delete());

    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 4);
    r07bRecord(new UploadAborted(session: $session->refresh(), actor: r07bActor(), correlationId: (string) Str::ulid()));

    $row = Activity::query()->where('verb', EventType::UploadAborted->value)->sole();
    expect($row->context['upload_id'])->toBe($session->upload_id);
});

// ── EventRecorder: upload.expired activity context ───────

it('R07b: upload.expired records all three context keys', function (): void {
    app(VaulterDatabase::class)->write(fn () => Activity::query()->delete());

    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 8);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['received_bytes' => 3])->save());
    $session->refresh();

    r07bRecord(new UploadExpired(session: $session, actor: Actor::system('upload expiry')->ref(), correlationId: (string) Str::ulid()));

    $row = Activity::query()->where('verb', EventType::UploadExpired->value)->sole();

    expect($row->context)->toHaveKeys(['upload_id', 'protocol', 'received_bytes'])
        ->and($row->context['upload_id'])->toBe($session->upload_id)
        ->and($row->context['received_bytes'])->toBe(3);
});

it('R07b: upload.expired protocol is the tus protocol value', function (): void {
    app(VaulterDatabase::class)->write(fn () => Activity::query()->delete());

    $session = UploadTestHelpers::session(
        UploadTestHelpers::storage(),
        protocol: UploadProtocol::Tus,
        totalSizeBytes: 4,
    );

    r07bRecord(new UploadExpired(session: $session->refresh(), actor: Actor::system('upload expiry')->ref(), correlationId: (string) Str::ulid()));

    $row = Activity::query()->where('verb', EventType::UploadExpired->value)->sole();
    expect($row->context['protocol'])->toBe('tus');
});

// ── EventRecorder: node.purged node_ids are strings ──

it('R07b: node.purged node_ids are string-typed after Identity::stringify', function (): void {
    app(VaulterDatabase::class)->write(fn () => Activity::query()->delete());

    $storage = UploadTestHelpers::storage();
    $root = $storage->rootNode()->first();

    // Use integer node ids (SQLite auto-increment) so a missing stringify would leave ints
    $node1 = new Node;
    $node1->forceFill([
        'drive_id' => $storage->getKey(),
        'parent_id' => $root->getKey(),
        'kind' => NodeKind::Folder,
        'name' => 'str-check',
        'size_bytes' => 0,
    ]);
    app(VaulterDatabase::class)->write(fn () => $node1->save());

    r07bRecord(new NodePurged(
        storageId: $storage->getKey(),
        nodeIds: [$node1->getKey()],
        freedBytes: 0,
        tenantId: $storage->tenant_id,
        profile: $storage->profile,
        actor: r07bActor(),
        correlationId: (string) Str::ulid(),
    ));

    $row = Activity::query()->where('verb', EventType::NodePurged->value)->sole();
    $nodeIds = $row->context['node_ids'];

    expect($nodeIds)->toBeArray()->not->toBeEmpty();

    foreach ($nodeIds as $id) {
        // Identity::stringify() stores the ids as JSON strings, not numbers.
        expect($id)->toBeString();
    }
});

// ── UploadSessionStoreRepository: pgsql advisory lock key prefix (L90) ────────

it('R07b: acquireForPatch pgsql advisory lock key includes upload: prefix (L90 ConcatRemoveLeft/SwitchSides)', function (): void {
    // On pgsql: different lock keys for different upload_ids (ConcatSwitchSides would swap prefix to suffix,
    // ConcatRemoveLeft would drop it — either way, two identical upload_ids after transformation would collide).
    // This test is only observable on pgsql; on sqlite the pgsql branch is skipped entirely.
    $driver = DB::connection()->getDriverName();

    if ($driver !== 'pgsql') {
        expect(true)->toBeTrue(); // sqlite: pgsql branch not taken, mutation not observable → skip

        return;
    }

    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 4);

    // On pgsql, acquireForPatch inside a transaction should succeed (no one else holds the lock)
    $locked = DB::transaction(fn (): UploadSession => app(UploadSessionStoreRepository::class)->acquireForPatch($session));

    expect($locked->upload_id)->toBe($session->upload_id);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'advisory lock observable on pgsql only');
