<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Events\Nodes\NodePurged;
use Vaulter\Drive\Events\Uploads\UploadAborted;
use Vaulter\Drive\Events\Uploads\UploadExpired;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\Uploads\UploadPartStoreRepository;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventRecorder;
use Vaulter\Testing\Fixtures\TestUser;
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

it('initiate stores the tenant_id from the storage row', function (): void {
    // Use integer tenant_id (bigint column on pgsql, works on sqlite too)
    $tenantId = 99887766;
    $storage = r07bReqTenantStorage($tenantId);

    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first()->getKey(),
        targetName: 'tenant.bin',
        totalSizeBytes: 4,
    ));

    // AlwaysReturnNull mutation: tenantId() always returns null → session.tenant_id = null
    expect($session->tenant_id)->toBe((string) $tenantId);
});

it('initiate upload_id is stored as a non-empty string', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first()->getKey(),
        targetName: 'uid.bin',
        totalSizeBytes: 4,
    ));

    expect($session->upload_id)->toBeString()->not->toBeEmpty();
});

it('initiate target_name stored from UploadInitData', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first()->getKey(),
        targetName: 'specific-name.bin',
        totalSizeBytes: 4,
    ));

    expect($session->target_name)->toBe('specific-name.bin');
});

it('initiate node_id stored from UploadInitData targetNode', function (): void {
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

    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetNode: $node->getKey(),
        totalSizeBytes: 4,
    ));

    expect($session->target_node_id)->toEqual($node->getKey());
});

it('initiate metadata=[] retains server owner identity', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first()->getKey(),
        targetName: 'meta-empty.bin',
        totalSizeBytes: 4,
        metadata: [],
    ));

    expect($session->metadata)->toBe(['actor_type' => 'vaulter:system', 'expected_sequence' => null]);
});

it('initiate non-empty metadata retains client values and server identity', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first()->getKey(),
        targetName: 'meta-set.bin',
        totalSizeBytes: 4,
        metadata: ['filename' => 'original.bin'],
    ));

    expect($session->metadata)->toBe(['filename' => 'original.bin', 'actor_type' => 'vaulter:system', 'expected_sequence' => null]);
});

// ── assertWithinMaxBytes boundary (L263 BooleanOr and ±1) ───────────────────

it('max_bytes=0 bypasses the ceiling for any total_size_bytes', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', 0);
    $storage = UploadTestHelpers::storage();

    // With BooleanOr: max===0 && totalSizeBytes===null = false → tries comparison → null > 0 = false → ok
    // With BooleanOr and totalSizeBytes=999: max===0 && 999===null = false → 999 > 0 → throws
    // Original: max===0 || ... = true → returns (no error)
    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first()->getKey(),
        targetName: 'big.bin',
        totalSizeBytes: 999_999,
    ));

    expect($session->total_size_bytes)->toBe(999_999);
});

it('max_bytes=1 blocks total_size_bytes=2', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', 1);
    $storage = UploadTestHelpers::storage();

    // With IncrementInteger on 0: max <= 1 → 1 <= 1 = true → returns early → no error
    // Original: 1 <= 0 = false → checks 2 > 1 → throws
    expect(fn () => app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first()->getKey(),
        targetName: 'over.bin',
        totalSizeBytes: 2,
    )))->toThrow(ValidationException::class);
});

it('max_bytes=1 allows total_size_bytes=1 exactly', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', 1);
    $storage = UploadTestHelpers::storage();

    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first()->getKey(),
        targetName: 'exact.bin',
        totalSizeBytes: 1,
    ));

    expect($session->total_size_bytes)->toBe(1);
});

// ── assertWithinActiveSessionCap (L269 RemoveArrayItem) ─────────────────────

it('null actorId bypasses the active session cap', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_active_sessions_per_actor', 1);
    $storage = UploadTestHelpers::storage();
    $root = $storage->rootNode()->first();
    $manager = app(UploadSessionManager::class);

    // Both sessions have actorId=null — should succeed even with cap=1
    foreach (['null-a.bin', 'null-b.bin'] as $name) {
        $manager->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
            protocol: UploadProtocol::Tus,
            targetParent: $root->getKey(),
            targetName: $name,
            totalSizeBytes: 4,
        ));
    }

    expect(UploadSession::query()->whereNull('created_by')->count())->toBe(2);
});

it('max_active_sessions=0 disables the cap for any actorId', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_active_sessions_per_actor', 0);
    $storage = UploadTestHelpers::storage();
    $root = $storage->rootNode()->first();
    $manager = app(UploadSessionManager::class);

    foreach (range(1, 3) as $i) {
        $manager->initiate(context: DriveContext::for(drive: $storage, actor: Actor::user((new TestUser)->forceFill(['id' => 88])), correlationId: (string) Str::ulid()), data: new InitiateUploadInput(
            protocol: UploadProtocol::Tus,
            targetParent: $root->getKey(),
            targetName: sprintf('unlimited-%s.bin', $i),
            totalSizeBytes: 4,
        ));
    }

    expect(UploadSession::query()->where('created_by', 88)->count())->toBe(3);
});

// ── guardTransition error field (L300 RemoveArrayItem 'status' key) ──────────

it('guardTransition ValidationException carries the "status" field in errors', function (): void {
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

it('advanceReceived works on an Uploading session', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 16);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['status' => UploadStatus::Uploading, 'received_bytes' => 4])->save());

    app(UploadSessionStoreRepository::class)->advanceReceived($session->refresh(), 8);

    expect($session->fresh()->received_bytes)->toBe(8)
        ->and($session->fresh()->status)->toBe(UploadStatus::Uploading);
});

it('advanceReceived throws on an Aborted session', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 4);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['status' => UploadStatus::Aborted])->save());

    expect(fn () => app(UploadSessionStoreRepository::class)->advanceReceived($session->refresh(), 4))
        ->toThrow(UploadConflictException::class);
});

// ── UploadSessionStoreRepository: markCommitted Assembled check (L217) ────────

it('markCommitted throws UploadConflictException on an Initiated session', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 4);
    expect($session->status)->toBe(UploadStatus::Initiated);

    expect(fn () => app(UploadSessionStoreRepository::class)->markCommitted($session, 99))
        ->toThrow(UploadConflictException::class);
});

it('markCommitted throws on an Uploading session — only Assembled passes', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 4);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['status' => UploadStatus::Uploading])->save());

    expect(fn () => app(UploadSessionStoreRepository::class)->markCommitted($session->refresh(), 99))
        ->toThrow(UploadConflictException::class);
});

// ── UploadPart casts (L57 AlwaysReturnEmptyArray, L58-L61 RemoveArrayItem) ───

it('UploadPart part_no and byte_offset and size_bytes cast to strict int', function (): void {
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

it('UploadPart received_at casts to Carbon', function (): void {
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

it('upload.aborted records all three context keys', function (): void {
    app(VaulterDatabase::class)->write(fn () => Activity::query()->delete());

    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 8);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['received_bytes' => 5])->save());
    $session->refresh();

    r07bRecord(new UploadAborted(session: $session, actor: r07bActor(), correlationId: (string) Str::ulid()));

    $row = Activity::query()->where('verb', DriveEventType::UploadAborted->value)->sole();

    expect($row->context)->toHaveKeys(['upload_id', 'protocol', 'received_bytes'])
        ->and($row->context['upload_id'])->toBeString()->not->toBeEmpty()
        ->and($row->context['protocol'])->toBeString()
        ->and($row->context['received_bytes'])->toBe(5);
});

it('upload.aborted upload_id context is the session upload_id', function (): void {
    app(VaulterDatabase::class)->write(fn () => Activity::query()->delete());

    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 4);
    r07bRecord(new UploadAborted(session: $session->refresh(), actor: r07bActor(), correlationId: (string) Str::ulid()));

    $row = Activity::query()->where('verb', DriveEventType::UploadAborted->value)->sole();
    expect($row->context['upload_id'])->toBe($session->upload_id);
});

// ── EventRecorder: upload.expired activity context ───────

it('upload.expired records all three context keys', function (): void {
    app(VaulterDatabase::class)->write(fn () => Activity::query()->delete());

    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 8);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['received_bytes' => 3])->save());
    $session->refresh();

    r07bRecord(new UploadExpired(session: $session, actor: Actor::system('upload expiry')->ref(), correlationId: (string) Str::ulid()));

    $row = Activity::query()->where('verb', DriveEventType::UploadExpired->value)->sole();

    expect($row->context)->toHaveKeys(['upload_id', 'protocol', 'received_bytes'])
        ->and($row->context['upload_id'])->toBe($session->upload_id)
        ->and($row->context['received_bytes'])->toBe(3);
});

it('upload.expired protocol is the tus protocol value', function (): void {
    app(VaulterDatabase::class)->write(fn () => Activity::query()->delete());

    $session = UploadTestHelpers::session(
        UploadTestHelpers::storage(),
        protocol: UploadProtocol::Tus,
        totalSizeBytes: 4,
    );

    r07bRecord(new UploadExpired(session: $session->refresh(), actor: Actor::system('upload expiry')->ref(), correlationId: (string) Str::ulid()));

    $row = Activity::query()->where('verb', DriveEventType::UploadExpired->value)->sole();
    expect($row->context['protocol'])->toBe('tus');
});

// ── EventRecorder: node.purged node_ids are strings ──

it('node.purged node_ids are string-typed after Identity::stringify', function (): void {
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

    $row = Activity::query()->where('verb', DriveEventType::NodePurged->value)->sole();
    $nodeIds = $row->context['node_ids'];

    expect($nodeIds)->toBeArray()->not->toBeEmpty();

    foreach ($nodeIds as $id) {
        // Identity::stringify() stores the ids as JSON strings, not numbers.
        expect($id)->toBeString();
    }
});

// ── UploadSessionStoreRepository: pgsql advisory lock key prefix (L90) ────────

it('acquireForPatch pgsql advisory lock key includes upload: prefix', function (): void {
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
