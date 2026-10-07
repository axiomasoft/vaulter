<?php

declare(strict_types=1);

use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Http\Requests\Files\UploadFileRequest;
use Vaulter\Drive\Http\Requests\Uploads\CreateUploadSessionRequest;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Node;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter-drive.uploads.allowed_mime_types', []);
    $this->actingAs((new TestUser)->forceFill(['id' => 17]));
});

// ── helpers ──────────────────────────────────────────────────────────────────

function r07bReqBuildSession(array $data): CreateUploadSessionRequest
{
    $http = HttpRequest::create('/', 'POST', $data);
    $http->headers->set('Accept', 'application/json');
    $req = CreateUploadSessionRequest::createFrom($http);
    $req->setContainer(app());

    return $req;
}

function r07bReqBuildFile(array $data): UploadFileRequest
{
    $http = HttpRequest::create('/', 'POST', $data);
    $http->headers->set('Accept', 'application/json');
    $req = UploadFileRequest::createFrom($http);
    $req->setContainer(app());

    return $req;
}

function r07bReqStorageBase(): string
{
    return 'api/vaulter/storages/'.Vaulter::drive(DriveTestHelpers::host())->model()->getKey();
}

function r07bReqUploadId(string $storageId, array $extra = []): string
{
    return test()->postJson('api/vaulter/storages/'.$storageId.'/resumable-uploads', [
        'name' => 'r07b.bin',
        'total_size_bytes' => 8,
        ...$extra,
    ])->assertCreated()->json('data.upload_id');
}

// ── CreateUploadSessionRequest: totalSizeBytes() int cast (L87 RemoveIntegerCast) ──

it('R07b: totalSizeBytes() casts numeric string to strict int (L87 RemoveIntegerCast)', function (): void {
    $req = r07bReqBuildSession(['total_size_bytes' => '8']);
    expect($req->totalSizeBytes())->toBe(8); // strict int 8, not '8'
});

it('R07b: totalSizeBytes() returns null for a non-numeric string (L87 boundary)', function (): void {
    expect(r07bReqBuildSession(['total_size_bytes' => 'abc'])->totalSizeBytes())->toBeNull();
});

it('R07b: totalSizeBytes() returns null when key is absent (L87 boundary)', function (): void {
    expect(r07bReqBuildSession([])->totalSizeBytes())->toBeNull();
});

// ── CreateUploadSessionRequest: expectedSha256() (L94) ───────────────────────

it('R07b: expectedSha256() returns the full 64-char hash string (L94 AlwaysReturnNull/TernaryNegated)', function (): void {
    $sha256 = str_repeat('e', 64);
    expect(r07bReqBuildSession(['expected_sha256' => $sha256])->expectedSha256())->toBe($sha256);
});

it('R07b: expectedSha256() returns null for empty string (L94 EmptyStringToNotEmpty/NotIdenticalToIdentical)', function (): void {
    expect(r07bReqBuildSession(['expected_sha256' => ''])->expectedSha256())->toBeNull();
});

it('R07b: expectedSha256() returns null when key is absent (L94 BooleanAnd)', function (): void {
    expect(r07bReqBuildSession([])->expectedSha256())->toBeNull();
});

// ── CreateUploadSessionRequest: mimeType() (L101) ────────────────────────────

it('R07b: mimeType() returns the string for a non-empty input (L101 AlwaysReturnNull/BooleanAnd)', function (): void {
    expect(r07bReqBuildSession(['mime_type' => 'application/pdf'])->mimeType())->toBe('application/pdf');
});

it('R07b: mimeType() returns null for empty string (L101 EmptyStringToNotEmpty)', function (): void {
    expect(r07bReqBuildSession(['mime_type' => ''])->mimeType())->toBeNull();
});

it('R07b: mimeType() returns null when key is absent (L101)', function (): void {
    expect(r07bReqBuildSession([])->mimeType())->toBeNull();
});

// ── CreateUploadSessionRequest: name() (L80) ─────────────────────────────────

it('R07b: name() returns null for a non-string truthy value — kills BooleanAndToBooleanOr (L80)', function (): void {
    // Inject a boolean directly, bypassing JSON coercion (tests is_string guard)
    $http = HttpRequest::create('/', 'POST');
    $http->request->set('name', true);
    $req = CreateUploadSessionRequest::createFrom($http);
    $req->setContainer(app());
    // BooleanAndToBooleanOr: is_string(true) || true !== '' = false || true = true → returns (bool) true
    // Original:              is_string(true) && ...         = false             → returns null
    expect($req->name())->toBeNull();
});

it('R07b: name() returns null for empty string (L80 EmptyStringToNotEmpty)', function (): void {
    expect(r07bReqBuildSession(['name' => ''])->name())->toBeNull();
});

it('R07b: name() returns the string for a non-empty string (L80 sanity)', function (): void {
    expect(r07bReqBuildSession(['name' => 'file.bin'])->name())->toBe('file.bin');
});

// ── CreateUploadSessionRequest: rules() RemoveArrayItem (L29-35) ─────────────

it('R07b: resumable session rules() enforces protocol, name, sha256, mime and metadata (L29-35 RemoveArrayItem)', function (): void {
    $base = r07bReqStorageBase().'/resumable-uploads';

    // L29: protocol in:... rule
    $this->postJson($base, ['protocol' => 'unknown-proto', 'name' => 'f.bin', 'total_size_bytes' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('errors.protocol', fn ($e) => is_array($e) && count($e) > 0);

    // L32: name max:255 rule
    $this->postJson($base, ['name' => str_repeat('x', 256), 'total_size_bytes' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name', fn ($e) => is_array($e) && count($e) > 0);

    // L33: total_size_bytes min:0 rule
    $this->postJson($base, ['name' => 'f.bin', 'total_size_bytes' => -1])
        ->assertUnprocessable()
        ->assertJsonPath('errors.total_size_bytes', fn ($e) => is_array($e) && count($e) > 0);

    // L34: expected_sha256 size:64 rule (63 chars is too short)
    $this->postJson($base, ['name' => 'f.bin', 'total_size_bytes' => 1, 'expected_sha256' => str_repeat('a', 63)])
        ->assertUnprocessable()
        ->assertJsonPath('errors.expected_sha256', fn ($e) => is_array($e) && count($e) > 0);

    // L35: mime_type max:191 rule
    $this->postJson($base, ['name' => 'f.bin', 'total_size_bytes' => 1, 'mime_type' => str_repeat('m', 192)])
        ->assertUnprocessable()
        ->assertJsonPath('errors.mime_type', fn ($e) => is_array($e) && count($e) > 0);

    // L36: metadata array rule
    $this->postJson($base, ['name' => 'f.bin', 'total_size_bytes' => 1, 'metadata' => 'scalar'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.metadata', fn ($e) => is_array($e) && count($e) > 0);
});

// ── UploadFileRequest: name() (L91) ──────────────────────────────────────────

it('R07b: UploadFileRequest name() returns null for empty string (L91 EmptyStringToNotEmpty)', function (): void {
    expect(r07bReqBuildFile(['name' => ''])->name())->toBeNull();
});

it('R07b: UploadFileRequest name() returns null for non-string (L91 BooleanAndToBooleanOr)', function (): void {
    $http = HttpRequest::create('/', 'POST');
    $http->request->set('name', 42);
    $req = UploadFileRequest::createFrom($http);
    $req->setContainer(app());
    expect($req->name())->toBeNull();
});

it('R07b: UploadFileRequest name() returns the string for a non-empty value (L91 sanity)', function (): void {
    expect(r07bReqBuildFile(['name' => 'photo.jpg'])->name())->toBe('photo.jpg');
});

// ── UploadFileRequest: rules() RemoveArrayItem (L29/41/42/43) ────────────────

it('R07b: UploadFileRequest rules() has file required, name max and name regex (L29/41/42/43)', function (): void {
    $base = r07bReqStorageBase().'/uploads';

    // L29: file required
    $this->postJson($base, [])->assertUnprocessable()
        ->assertJsonPath('errors.file', fn ($e) => is_array($e) && count($e) > 0);

    // L43: name max:255
    $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('t.txt', 'data'),
        'name' => str_repeat('z', 256),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name', fn ($e) => is_array($e) && count($e) > 0);

    // L43: name regex (path separator)
    $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('t.txt', 'data'),
        'name' => 'bad/name',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name', fn ($e) => is_array($e) && count($e) > 0);
});

// ── PurgeTrashCommand: retention eligibility and reported root counts ──────────

it('R07b: one-day retention purges an old root and preserves one inside the window', function (): void {
    $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
    $storage = AccessTestHelpers::storage();
    $old = AccessTestHelpers::folder($storage, null, 'one-day-old');
    $recent = AccessTestHelpers::folder($storage, null, 'one-day-recent');
    $old->forceFill(['trashed_at' => Carbon::now()->subDays(2)])->save();
    $recent->forceFill(['trashed_at' => Carbon::now()->subDay()->addSecond()])->save();

    expect(Artisan::call('vaulter:purge-trash', ['--days' => '1']))->toBe(0)
        ->and(Artisan::output())->toContain('Purged 1 trashed subtree.')
        ->and(Node::withTrashed()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Node::withTrashed()->whereKey($recent->id)->exists())->toBeTrue();
});

it('R07b: two-day retention includes the exact cutoff and reports two purged roots', function (): void {
    $this->travelTo(Carbon::parse('2026-09-21 12:00:00'));
    $storage = AccessTestHelpers::storage();
    $old = AccessTestHelpers::folder($storage, null, 'two-day-old');
    $boundary = AccessTestHelpers::folder($storage, null, 'two-day-boundary');
    $recent = AccessTestHelpers::folder($storage, null, 'two-day-recent');
    $old->forceFill(['trashed_at' => Carbon::now()->subDays(3)])->save();
    $boundary->forceFill(['trashed_at' => Carbon::now()->subDays(2)])->save();
    $recent->forceFill(['trashed_at' => Carbon::now()->subDays(2)->addSecond()])->save();

    expect(Artisan::call('vaulter:purge-trash', ['--days' => '2']))->toBe(0)
        ->and(Artisan::output())->toContain('Purged 2 trashed subtrees.')
        ->and(Node::withTrashed()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Node::withTrashed()->whereKey($boundary->id)->exists())->toBeFalse()
        ->and(Node::withTrashed()->whereKey($recent->id)->exists())->toBeTrue();
});

it('R07b: zero retention purges a root trashed now while leaving live nodes', function (): void {
    $this->travelTo(Carbon::parse('2026-09-22 10:00:00'));
    $storage = AccessTestHelpers::storage();
    $trashed = AccessTestHelpers::folder($storage, null, 'just-trashed');
    $live = AccessTestHelpers::folder($storage, null, 'live');
    $trashed->forceFill(['trashed_at' => Carbon::now()])->save();

    expect(Artisan::call('vaulter:purge-trash', ['--days' => '0']))->toBe(0)
        ->and(Artisan::output())->toContain('Purged 1 trashed subtree.')
        ->and(Node::withTrashed()->whereKey($trashed->id)->exists())->toBeFalse()
        ->and(Node::withTrashed()->whereKey($live->id)->exists())->toBeTrue();
});

it('R07b: non-numeric days uses configured retention rather than zero', function (): void {
    config()->set('vaulter-drive.trash.retention_days', 14);
    $this->travelTo(Carbon::parse('2026-09-22 11:00:00'));
    $storage = AccessTestHelpers::storage();
    $old = AccessTestHelpers::folder($storage, null, 'default-old');
    $recent = AccessTestHelpers::folder($storage, null, 'default-recent');
    $old->forceFill(['trashed_at' => Carbon::now()->subDays(15)])->save();
    $recent->forceFill(['trashed_at' => Carbon::now()->subDays(7)])->save();

    expect(Artisan::call('vaulter:purge-trash', ['--days' => 'not-a-number']))->toBe(0)
        ->and(Artisan::output())->toContain('Purged 1 trashed subtree.')
        ->and(Node::withTrashed()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Node::withTrashed()->whereKey($recent->id)->exists())->toBeTrue();
});

// ── PurgeTrashCommand: ForceDeleteNodeAction (L94 RemoveMethodCall) ───────────

it('R07b: purge-trash decrements storage used_bytes — ForceDeleteNodeAction must run (L94)', function (): void {
    $drive = DriveTestHelpers::drive();
    $storage = $drive->model();

    $file = $drive->files()->upload(UploadSource::fromString('bytes for quota test', 'quota-r07b.txt'));
    $usedAfterUpload = $storage->refresh()->used_bytes;
    expect($usedAfterUpload)->toBeGreaterThan(0);

    $file->forceFill(['trashed_at' => Carbon::now()->subDay()])->save();

    Artisan::call('vaulter:purge-trash', ['--days' => '0']);

    // ForceDeleteNodeAction releases quota via NodeStoreRepository; without it, used_bytes stays elevated
    expect($storage->refresh()->used_bytes)->toBeLessThan($usedAfterUpload);
});

// ── PurgeTrashCommand: purgeSubtree (L99 RemoveMethodCall) ───────────────────

it('R07b: purge-trash physically removes rows — withTrashed still finds nothing (L99)', function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 12:00:00'));
    $storage = AccessTestHelpers::storage();
    $n = AccessTestHelpers::folder($storage, null, 'r07b-physical');
    $n->forceFill(['trashed_at' => Carbon::now()->subDays(2)])->save();
    $nodeId = $n->getKey();

    Artisan::call('vaulter:purge-trash', ['--days' => '1']);

    // Row must be physically deleted; withTrashed reveals soft-deleted but not purged rows
    expect(Node::withTrashed()->whereKey($nodeId)->exists())->toBeFalse();
});

// ── PurgeTrashCommand: purgedRoots / purgedNodeCount counting (L105/107) ──────

it('R07b: purge-trash increments purgedRoots correctly for multiple subtrees (L105/L107)', function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 12:00:00'));
    $storage = AccessTestHelpers::storage();

    foreach (['root-p', 'root-q', 'root-r'] as $name) {
        $n = AccessTestHelpers::folder($storage, null, $name);
        $n->forceFill(['trashed_at' => Carbon::now()->subDays(2)])->save();
    }

    Artisan::call('vaulter:purge-trash', ['--days' => '1']);
    $out = Artisan::output();

    expect($out)->toContain('Purged 3 trashed subtrees.');
});

// ── PurgeTrashCommand: vaulter_purge_nodes_total counter (L113/L114) ──────────

it('R07b: purge-trash emits vaulter_purge_nodes_total when nodes are purged (L114)', function (): void {
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);
    app()->forgetInstance(MetricsRecorder::class);

    $this->travelTo(Carbon::parse('2026-09-25 12:00:00'));
    $storage = AccessTestHelpers::storage();
    $n = AccessTestHelpers::folder($storage, null, 'r07b-counter');
    $n->forceFill(['trashed_at' => Carbon::now()->subDays(2)])->save();

    Artisan::call('vaulter:purge-trash', ['--days' => '1']);

    /** @var FakeMetricsRecorder $recorder */
    $recorder = app(MetricsRecorder::class);
    $recorder->assertCounter(name: 'vaulter_purge_nodes_total');

    // Confirm via emissions() so Pest registers a real assertion
    $names = array_column($recorder->emissions(), 'name');
    expect($names)->toContain('vaulter_purge_nodes_total');
});

// ── TusUploadController: max_chunk_bytes ceiling (L390 IncrementInteger on 0) ──

it('R07b: max_chunk_bytes=1 blocks a 2-byte declared chunk with 413 (L390 IncrementInteger)', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 1);
    $storageId = r07bReqStorageBase();
    $uploadId = r07bReqUploadId(explode('/', $storageId)[3], ['total_size_bytes' => 16]);

    $response = $this->call('PATCH', 'api/vaulter/resumable-uploads/'.$uploadId, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => '2',
        'HTTP_UPLOAD_OFFSET' => '0',
    ], content: 'ab');

    // With IncrementInteger on L390 `$max <= 0`, it becomes `$max <= 1`:
    // max=1 → 1 <= 1 = true → returns early, no 413 (mutation lives)
    // Original: 1 <= 0 = false → checks chunk size → 2 > 1 → 413
    $response->assertStatus(413);
});

it('R07b: max_chunk_bytes=1 allows an exactly 1-byte chunk (L390 boundary)', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 1);
    $storageId = r07bReqStorageBase();
    $uploadId = r07bReqUploadId(explode('/', $storageId)[3], ['total_size_bytes' => 16]);

    $this->call('PATCH', 'api/vaulter/resumable-uploads/'.$uploadId, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => '1',
        'HTTP_UPLOAD_OFFSET' => '0',
    ], content: 'a')->assertNoContent()->assertHeader('Upload-Offset', '1');
});

// ── TusUploadController: sanitizeMetadata ContinueToBreak (L433) ─────────────

it('R07b: sanitizeMetadata continues after unknown key and still includes known keys that follow (L433 ContinueToBreak)', function (): void {
    config()->set('vaulter-drive.uploads.resumable.metadata.allowed_keys', ['filename']);
    $storageId = explode('/', r07bReqStorageBase())[3];

    // JSON body metadata: 'unknown_first' key comes before 'filename'
    // ContinueToBreak: break stops the foreach at 'unknown_first' → 'filename' never processed → empty result
    // Original: continue skips 'unknown_first' → 'filename' is included
    $uploadId = $this->postJson('api/vaulter/storages/'.$storageId.'/resumable-uploads', [
        'name' => 'meta.bin',
        'total_size_bytes' => 4,
    ], [
        'Upload-Metadata' => 'unknown_first '.base64_encode('x').', filename '.base64_encode('break-test.bin'),
    ])->assertCreated()->json('data.upload_id');

    expect(UploadTestHelpers::clientMetadata(UploadSession::query()->where('upload_id', $uploadId)->sole()))
        ->toBe(['filename' => 'break-test.bin']);
});

// ── TusUploadController: decodeUploadMetadata empty key (L462 EmptyStringToNotEmpty) ──

it('R07b: decodeUploadMetadata skips segments with an empty key (L462 EmptyStringToNotEmpty)', function (): void {
    config()->set('vaulter-drive.uploads.resumable.metadata.allowed_keys', ['filename']);
    $storageId = explode('/', r07bReqStorageBase())[3];

    // Double-comma creates an empty segment: ', ,' splits into ' ' which trims to ''
    $uploadId = $this->postJson('api/vaulter/storages/'.$storageId.'/resumable-uploads', [
        'name' => 'empty-key.bin',
        'total_size_bytes' => 4,
    ], [
        'Upload-Metadata' => 'filename '.base64_encode('good.bin').',,extra '.base64_encode('x'),
    ])->assertCreated()->json('data.upload_id');

    $metadata = UploadSession::query()->where('upload_id', $uploadId)->sole()->metadata;
    // Must contain 'filename' and NOT contain '' key
    expect($metadata)->toHaveKey('filename')
        ->and(array_keys($metadata))->not->toContain('');
});

// ── TusUploadController: abort manager call (L186 RemoveMethodCall) ───────────

it('R07b: DELETE abort transitions session to Aborted status (L186 RemoveMethodCall)', function (): void {
    $storageId = explode('/', r07bReqStorageBase())[3];
    $uploadId = r07bReqUploadId($storageId);

    $this->delete('api/vaulter/resumable-uploads/'.$uploadId, [], ['Tus-Resumable' => '1.0.0'])
        ->assertNoContent()
        ->assertHeader('Tus-Resumable', '1.0.0');

    expect(UploadSession::query()->where('upload_id', $uploadId)->sole()->status->value)
        ->toBe('aborted');
});
