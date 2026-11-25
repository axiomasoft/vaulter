<?php

declare(strict_types=1);

use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Http\Requests\Files\UploadFileRequest;
use Vaulter\Drive\Http\Requests\Uploads\CreateUploadSessionRequest;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Testing\Fixtures\TestUser;

beforeEach(fn () => DriveTestHelpers::insideOperation());

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
    return 'api/vaulter/v1/drives/'.Vaulter::drive(DriveTestHelpers::host())->model()->getKey();
}

function r07bReqUploadId(string $storageId, array $extra = []): string
{
    return test()->postJson('api/vaulter/v1/drives/'.$storageId.'/uploads', [
        'name' => 'r07b.bin',
        'total_size_bytes' => 8,
        ...$extra,
    ])->assertCreated()->json('data.upload_id');
}

// ── CreateUploadSessionRequest: totalSizeBytes() int cast (L87 RemoveIntegerCast) ──

it('totalSizeBytes() casts numeric string to strict int', function (): void {
    $req = r07bReqBuildSession(['total_size_bytes' => '8']);
    expect($req->totalSizeBytes())->toBe(8); // strict int 8, not '8'
});

it('totalSizeBytes() returns null for a non-numeric string', function (): void {
    expect(r07bReqBuildSession(['total_size_bytes' => 'abc'])->totalSizeBytes())->toBeNull();
});

it('totalSizeBytes() returns null when key is absent', function (): void {
    expect(r07bReqBuildSession([])->totalSizeBytes())->toBeNull();
});

// ── CreateUploadSessionRequest: expectedSha256() (L94) ───────────────────────

it('expectedSha256() returns the full 64-char hash string', function (): void {
    $sha256 = str_repeat('e', 64);
    expect(r07bReqBuildSession(['expected_sha256' => $sha256])->expectedSha256())->toBe($sha256);
});

it('expectedSha256() returns null for empty string', function (): void {
    expect(r07bReqBuildSession(['expected_sha256' => ''])->expectedSha256())->toBeNull();
});

it('expectedSha256() returns null when key is absent', function (): void {
    expect(r07bReqBuildSession([])->expectedSha256())->toBeNull();
});

// ── CreateUploadSessionRequest: mimeType() (L101) ────────────────────────────

it('mimeType() returns the string for a non-empty input', function (): void {
    expect(r07bReqBuildSession(['mime_type' => 'application/pdf'])->mimeType())->toBe('application/pdf');
});

it('mimeType() returns null for empty string', function (): void {
    expect(r07bReqBuildSession(['mime_type' => ''])->mimeType())->toBeNull();
});

it('mimeType() returns null when key is absent', function (): void {
    expect(r07bReqBuildSession([])->mimeType())->toBeNull();
});

// ── CreateUploadSessionRequest: name() (L80) ─────────────────────────────────

it('name() returns null for a non-string truthy value — kills BooleanAndToBooleanOr', function (): void {
    // Inject a boolean directly, bypassing JSON coercion (tests is_string guard)
    $http = HttpRequest::create('/', 'POST');
    $http->request->set('name', true);
    $req = CreateUploadSessionRequest::createFrom($http);
    $req->setContainer(app());
    // BooleanAndToBooleanOr: is_string(true) || true !== '' = false || true = true → returns (bool) true
    // Original:              is_string(true) && ...         = false             → returns null
    expect($req->name())->toBeNull();
});

it('name() returns null for empty string', function (): void {
    expect(r07bReqBuildSession(['name' => ''])->name())->toBeNull();
});

it('name() returns the string for a non-empty string', function (): void {
    expect(r07bReqBuildSession(['name' => 'file.bin'])->name())->toBe('file.bin');
});

// ── CreateUploadSessionRequest: rules() RemoveArrayItem (L29-35) ─────────────

it('resumable session rules() enforces protocol, name, sha256, mime and metadata', function (): void {
    $base = r07bReqStorageBase().'/uploads';

    // L29: protocol in:... rule
    $this->postJson($base, ['protocol' => 'unknown-proto', 'name' => 'f.bin', 'total_size_bytes' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('errors.protocol', fn ($e): bool => is_array($e) && count($e) > 0);

    // L32: name max:255 rule
    $this->postJson($base, ['name' => str_repeat('x', 256), 'total_size_bytes' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name', fn ($e): bool => is_array($e) && count($e) > 0);

    // L33: total_size_bytes min:0 rule
    $this->postJson($base, ['name' => 'f.bin', 'total_size_bytes' => -1])
        ->assertUnprocessable()
        ->assertJsonPath('errors.total_size_bytes', fn ($e): bool => is_array($e) && count($e) > 0);

    // L34: expected_sha256 size:64 rule (63 chars is too short)
    $this->postJson($base, ['name' => 'f.bin', 'total_size_bytes' => 1, 'expected_sha256' => str_repeat('a', 63)])
        ->assertUnprocessable()
        ->assertJsonPath('errors.expected_sha256', fn ($e): bool => is_array($e) && count($e) > 0);

    // L35: mime_type max:191 rule
    $this->postJson($base, ['name' => 'f.bin', 'total_size_bytes' => 1, 'mime_type' => str_repeat('m', 192)])
        ->assertUnprocessable()
        ->assertJsonPath('errors.mime_type', fn ($e): bool => is_array($e) && count($e) > 0);

    // L36: metadata array rule
    $this->postJson($base, ['name' => 'f.bin', 'total_size_bytes' => 1, 'metadata' => 'scalar'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.metadata', fn ($e): bool => is_array($e) && count($e) > 0);
});

// ── UploadFileRequest: name() (L91) ──────────────────────────────────────────

it('UploadFileRequest name() returns null for empty string', function (): void {
    expect(r07bReqBuildFile(['name' => ''])->name())->toBeNull();
});

it('UploadFileRequest name() returns null for non-string', function (): void {
    $http = HttpRequest::create('/', 'POST');
    $http->request->set('name', 42);
    $req = UploadFileRequest::createFrom($http);
    $req->setContainer(app());
    expect($req->name())->toBeNull();
});

it('UploadFileRequest name() returns the string for a non-empty value', function (): void {
    expect(r07bReqBuildFile(['name' => 'photo.jpg'])->name())->toBe('photo.jpg');
});

// ── UploadFileRequest: rules() RemoveArrayItem (L29/41/42/43) ────────────────

it('UploadFileRequest rules() has file required, name max and name regex', function (): void {
    $base = r07bReqStorageBase().'/files';

    // L29: file required
    $this->postJson($base, [])->assertUnprocessable()
        ->assertJsonPath('errors.file', fn ($e): bool => is_array($e) && count($e) > 0);

    // L43: name max:255
    $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('t.txt', 'data'),
        'name' => str_repeat('z', 256),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name', fn ($e): bool => is_array($e) && count($e) > 0);

    // L43: name regex (path separator)
    $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('t.txt', 'data'),
        'name' => 'bad/name',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name', fn ($e): bool => is_array($e) && count($e) > 0);
});

// ── PurgeTrashCommand: retention eligibility and reported root counts ──────────

it('one-day retention purges an old root and preserves one inside the window', function (): void {
    $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
    $storage = AccessTestHelpers::storage();
    $old = AccessTestHelpers::folder($storage, null, 'one-day-old');
    $recent = AccessTestHelpers::folder($storage, null, 'one-day-recent');
    $old->forceFill(['trashed_at' => Carbon::now()->subDays(2), 'trash_root_id' => $old->getKey()])->save();
    $recent->forceFill(['trashed_at' => Carbon::now()->subDay()->addSecond(), 'trash_root_id' => $recent->getKey()])->save();

    expect(Artisan::call('vaulter:trash:purge', ['--days' => '1']))->toBe(0)
        ->and(Artisan::output())->toContain('Purged 1 trashed subtree.')
        ->and(Node::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Node::query()->whereKey($recent->id)->exists())->toBeTrue();
});

it('two-day retention includes the exact cutoff and reports two purged roots', function (): void {
    $this->travelTo(Carbon::parse('2026-09-21 12:00:00'));
    $storage = AccessTestHelpers::storage();
    $old = AccessTestHelpers::folder($storage, null, 'two-day-old');
    $boundary = AccessTestHelpers::folder($storage, null, 'two-day-boundary');
    $recent = AccessTestHelpers::folder($storage, null, 'two-day-recent');
    $old->forceFill(['trashed_at' => Carbon::now()->subDays(3), 'trash_root_id' => $old->getKey()])->save();
    $boundary->forceFill(['trashed_at' => Carbon::now()->subDays(2), 'trash_root_id' => $boundary->getKey()])->save();
    $recent->forceFill(['trashed_at' => Carbon::now()->subDays(2)->addSecond(), 'trash_root_id' => $recent->getKey()])->save();

    expect(Artisan::call('vaulter:trash:purge', ['--days' => '2']))->toBe(0)
        ->and(Artisan::output())->toContain('Purged 2 trashed subtrees.')
        ->and(Node::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Node::query()->whereKey($boundary->id)->exists())->toBeFalse()
        ->and(Node::query()->whereKey($recent->id)->exists())->toBeTrue();
});

it('zero retention purges a root trashed now while leaving live nodes', function (): void {
    $this->travelTo(Carbon::parse('2026-09-22 10:00:00'));
    $storage = AccessTestHelpers::storage();
    $trashed = AccessTestHelpers::folder($storage, null, 'just-trashed');
    $live = AccessTestHelpers::folder($storage, null, 'live');
    $trashed->forceFill(['trashed_at' => Carbon::now(), 'trash_root_id' => $trashed->getKey()])->save();

    expect(Artisan::call('vaulter:trash:purge', ['--days' => '0']))->toBe(0)
        ->and(Artisan::output())->toContain('Purged 1 trashed subtree.')
        ->and(Node::query()->whereKey($trashed->id)->exists())->toBeFalse()
        ->and(Node::query()->whereKey($live->id)->exists())->toBeTrue();
});

it('non-numeric days uses configured retention rather than zero', function (): void {
    config()->set('vaulter-drive.trash.retention_days', 14);
    $this->travelTo(Carbon::parse('2026-09-22 11:00:00'));
    $storage = AccessTestHelpers::storage();
    $old = AccessTestHelpers::folder($storage, null, 'default-old');
    $recent = AccessTestHelpers::folder($storage, null, 'default-recent');
    $old->forceFill(['trashed_at' => Carbon::now()->subDays(15), 'trash_root_id' => $old->getKey()])->save();
    $recent->forceFill(['trashed_at' => Carbon::now()->subDays(7), 'trash_root_id' => $recent->getKey()])->save();

    expect(Artisan::call('vaulter:trash:purge', ['--days' => 'not-a-number']))->toBe(0)
        ->and(Artisan::output())->toContain('Purged 1 trashed subtree.')
        ->and(Node::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Node::query()->whereKey($recent->id)->exists())->toBeTrue();
});

// ── PurgeTrashCommand: ForceDeleteNodeAction (L94 RemoveMethodCall) ───────────

it('purge-trash decrements storage used_bytes — ForceDeleteNodeAction must run', function (): void {
    $drive = DriveTestHelpers::drive();
    $storage = $drive->model();

    $file = $drive->files()->upload(UploadSource::fromString('bytes for quota test', 'quota-r07b.txt'));
    $usedAfterUpload = $storage->refresh()->used_bytes;
    expect($usedAfterUpload)->toBeGreaterThan(0);

    DriveTestHelpers::model($file)->forceFill(['trashed_at' => Carbon::now()->subDay(), 'trash_root_id' => $file->id])->save();

    Artisan::call('vaulter:trash:purge', ['--days' => '0']);

    // ForceDeleteNodeAction releases quota via NodeStoreRepository; without it, used_bytes stays elevated
    expect($storage->refresh()->used_bytes)->toBeLessThan($usedAfterUpload);
});

// ── PurgeTrashCommand: purgeSubtree (L99 RemoveMethodCall) ───────────────────

it('purge-trash physically removes rows — no query finds them afterwards', function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 12:00:00'));
    $storage = AccessTestHelpers::storage();
    $n = AccessTestHelpers::folder($storage, null, 'r07b-physical');
    $n->forceFill(['trashed_at' => Carbon::now()->subDays(2), 'trash_root_id' => $n->getKey()])->save();
    $nodeId = $n->getKey();

    Artisan::call('vaulter:trash:purge', ['--days' => '1']);

    // Row must be physically deleted (nodes have no soft-delete tombstone)
    expect(Node::query()->whereKey($nodeId)->exists())->toBeFalse();
});

// ── PurgeTrashCommand: purgedRoots / purgedNodeCount counting (L105/107) ──────

it('purge-trash increments purgedRoots correctly for multiple subtrees', function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 12:00:00'));
    $storage = AccessTestHelpers::storage();

    foreach (['root-p', 'root-q', 'root-r'] as $name) {
        $n = AccessTestHelpers::folder($storage, null, $name);
        $n->forceFill(['trashed_at' => Carbon::now()->subDays(2), 'trash_root_id' => $n->getKey()])->save();
    }

    Artisan::call('vaulter:trash:purge', ['--days' => '1']);
    $out = Artisan::output();

    expect($out)->toContain('Purged 3 trashed subtrees.');
});

// ── PurgeTrashCommand: vaulter_purge_nodes_total counter (L113/L114) ──────────

it('purge-trash emits vaulter_purge_nodes_total when nodes are purged', function (): void {
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);
    app()->forgetInstance(MetricsRecorder::class);

    $this->travelTo(Carbon::parse('2026-09-25 12:00:00'));
    $storage = AccessTestHelpers::storage();
    $n = AccessTestHelpers::folder($storage, null, 'r07b-counter');
    $n->forceFill(['trashed_at' => Carbon::now()->subDays(2), 'trash_root_id' => $n->getKey()])->save();

    Artisan::call('vaulter:trash:purge', ['--days' => '1']);

    /** @var FakeMetricsRecorder $recorder */
    $recorder = app(MetricsRecorder::class);
    $recorder->assertCounter(name: 'vaulter_purge_nodes_total');

    // Confirm via emissions() so Pest registers a real assertion
    $names = array_column($recorder->emissions(), 'name');
    expect($names)->toContain('vaulter_purge_nodes_total');
});

// ── TusUploadController: max_chunk_bytes ceiling (L390 IncrementInteger on 0) ──

it('max_chunk_bytes=1 blocks a 2-byte declared chunk with 413', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 1);
    $storageId = r07bReqStorageBase();
    $uploadId = r07bReqUploadId(explode('/', $storageId)[4], ['total_size_bytes' => 16]);

    $response = $this->call('PATCH', 'api/vaulter/v1/uploads/'.$uploadId, server: [
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

it('max_chunk_bytes=1 allows an exactly 1-byte chunk', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 1);
    $storageId = r07bReqStorageBase();
    $uploadId = r07bReqUploadId(explode('/', $storageId)[4], ['total_size_bytes' => 16]);

    $this->call('PATCH', 'api/vaulter/v1/uploads/'.$uploadId, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => '1',
        'HTTP_UPLOAD_OFFSET' => '0',
    ], content: 'a')->assertNoContent()->assertHeader('Upload-Offset', '1');
});

// ── TusUploadController: sanitizeMetadata ContinueToBreak (L433) ─────────────

it('sanitizeMetadata continues after unknown key and still includes known keys that follow', function (): void {
    config()->set('vaulter-drive.uploads.resumable.metadata.allowed_keys', ['filename']);
    $storageId = explode('/', r07bReqStorageBase())[4];

    // JSON body metadata: 'unknown_first' key comes before 'filename'
    // ContinueToBreak: break stops the foreach at 'unknown_first' → 'filename' never processed → empty result
    // Original: continue skips 'unknown_first' → 'filename' is included
    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storageId.'/uploads', [
        'name' => 'meta.bin',
        'total_size_bytes' => 4,
    ], [
        'Upload-Metadata' => 'unknown_first '.base64_encode('x').', filename '.base64_encode('break-test.bin'),
    ])->assertCreated()->json('data.upload_id');

    expect(UploadTestHelpers::clientMetadata(UploadSession::query()->where('upload_id', $uploadId)->sole()))
        ->toBe(['filename' => 'break-test.bin']);
});

// ── TusUploadController: decodeUploadMetadata empty key (L462 EmptyStringToNotEmpty) ──

it('decodeUploadMetadata skips segments with an empty key', function (): void {
    config()->set('vaulter-drive.uploads.resumable.metadata.allowed_keys', ['filename']);
    $storageId = explode('/', r07bReqStorageBase())[4];

    // Double-comma creates an empty segment: ', ,' splits into ' ' which trims to ''
    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storageId.'/uploads', [
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

it('DELETE abort transitions session to Aborted status', function (): void {
    $storageId = explode('/', r07bReqStorageBase())[4];
    $uploadId = r07bReqUploadId($storageId);

    $this->delete('api/vaulter/v1/uploads/'.$uploadId, [], ['Tus-Resumable' => '1.0.0'])
        ->assertNoContent()
        ->assertHeader('Tus-Resumable', '1.0.0');

    expect(UploadSession::query()->where('upload_id', $uploadId)->sole()->status->value)
        ->toBe('aborted');
});
