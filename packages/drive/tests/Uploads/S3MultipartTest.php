<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use Psr\Http\Message\RequestInterface;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Contracts\Uploads\MultipartUploadPort;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Jobs\Uploads\ScanJob;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

beforeEach(function (): void {
    if (!class_exists(MockHandler::class) || !class_exists(PortableVisibilityConverter::class)) {
        test()->markTestSkipped('aws/aws-sdk-php or league/flysystem-aws-s3-v3 is not installed.');
    }
});
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Fixtures\Access\ProfileCreateGate;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Media\Content\ScanResult;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Enums\Cas\ScanVerdict;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Testing\FakeContentScanner;

// These tests drive handlers and repositories directly; Vaulter's entry points open the write scope, these calls do not.
beforeEach(fn () => DriveTestHelpers::insideOperation());

/**
 * The S3-multipart path exercised against the FAKED 'local' disk (a real S3 is not
 * required — the handler proxies parts through the StorageDriver, so the same flow
 * holds; a host swaps the handler for a presigned-PUT one in production). The
 * invariant — assemble → server-recompute sha256 → BlobStore.commit — is verified
 * identically to the tus path.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

function initiateS3(int $totalBytes, string $name = 'multi.bin', ?Drive $storage = null): UploadSession
{
    $storage ??= UploadTestHelpers::storage();

    return app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::S3Multipart,
        targetParent: $storage->rootNode()->first()->getKey(),
        targetName: $name,
        totalSizeBytes: $totalBytes,
        mimeType: 'application/octet-stream',
    ));
}

it('initiate → parts → complete → server-recompute → commit', function (): void {
    $part1 = str_repeat('A', 12);
    $part2 = str_repeat('B', 8);
    $whole = $part1.$part2; // 20 bytes

    $session = initiateS3(totalBytes: strlen($whole), name: 'multi.bin');
    $handler = app(S3MultipartHandler::class);

    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor($part1));
    $handler->putPart($session->refresh(), partNo: 2, byteOffset: 12, chunk: UploadTestHelpers::streamFor($part2));

    // Both parts recorded; received_bytes reflects the sum.
    expect(UploadPart::query()->where('upload_session_id', $session->getKey())->count())->toBe(2)
        ->and($session->refresh()->received_bytes)->toBe(20);

    $handler->complete($session->refresh());

    app(UploadSessionManager::class)->markAssembled($session->refresh());
    $node = app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'test fixture'), session: $session->refresh());

    expect($node->kind)->toBe(NodeKind::File)
        ->and($node->size_bytes)->toBe(20)
        ->and(Blob::query()->count())->toBe(1);

    // The committed content hash is recomputed from the STITCHED bytes, not any ETag.
    $version = $node->file()->firstOrFail()->currentRevision()->first();
    expect($version->sha256)->toBe(hash('sha256', $whole))
        ->and($session->refresh()->status)->toBe(UploadStatus::Committed);
});

it('re-uploading a part REPLACES it (PK part_no), as in S3', function (): void {
    $session = initiateS3(totalBytes: 5, name: 'replace.bin');
    $handler = app(S3MultipartHandler::class);

    // Upload part 1, then re-upload it with different bytes.
    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('aaaaa'));
    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('bbbbb'));

    // Still ONE row for part 1 — the re-upload replaced it.
    expect(UploadPart::query()->where('upload_session_id', $session->getKey())->count())->toBe(1);

    $handler->complete($session->refresh());
    app(UploadSessionManager::class)->markAssembled($session->refresh());
    $node = app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'test fixture'), session: $session->refresh());

    // The committed bytes are the REPLACEMENT (bbbbb), not the original.
    $version = $node->file()->firstOrFail()->currentRevision()->first();
    expect($version->sha256)->toBe(hash('sha256', 'bbbbb'));
});

it('a lying ETag is not used as the address; the server recomputes sha256', function (): void {
    $bytes = 'real multipart bytes';
    $session = initiateS3(totalBytes: strlen($bytes), name: 'x.bin');
    $handler = app(S3MultipartHandler::class);

    $result = $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor($bytes));

    // The handler returns an etag (the object-store handle) but the COMMITTED hash is
    // the server-recomputed content sha256 — they are unrelated.
    $handler->complete($session->refresh());
    app(UploadSessionManager::class)->markAssembled($session->refresh());
    $node = app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'test fixture'), session: $session->refresh());

    $version = $node->file()->firstOrFail()->currentRevision()->first();
    expect($version->sha256)->toBe(hash('sha256', $bytes))
        ->and($version->sha256)->not->toBe(trim($result['etag'], '"'));
});

it('complete without parts is rejected', function (): void {
    $session = initiateS3(totalBytes: 10);

    expect(fn () => app(S3MultipartHandler::class)->complete($session->refresh()))
        ->toThrow(ValidationException::class);
});

it('streams multipart assembly with bounded complete memory', function (): void {
    $partBytes = 1024 * 1024;
    $partCount = 32;
    $session = initiateS3(totalBytes: $partBytes * $partCount, name: 'bounded.bin');
    $handler = app(S3MultipartHandler::class);
    $part = str_repeat('x', $partBytes);

    foreach (range(1, $partCount) as $partNo) {
        $handler->putPart(
            $session->refresh(),
            partNo: $partNo,
            byteOffset: ($partNo - 1) * $partBytes,
            chunk: UploadTestHelpers::streamFor($part),
        );
    }

    unset($part);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage(true);

    expect($handler->complete($session->refresh()))->toBe($partBytes * $partCount)
        ->and(memory_get_peak_usage(true) - $before)->toBeLessThan(16 * 1024 * 1024)
        ->and(app(StagedUpload::class)->size($session->refresh()))
        ->toBe($partBytes * $partCount);
});

it('abort deletes the per-part objects and marks the session aborted', function (): void {
    $session = initiateS3(totalBytes: 10, name: 'abort.bin');
    $handler = app(S3MultipartHandler::class);

    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('1234567890'));

    Storage::disk('local')->assertExists("tmp/uploads/{$session->upload_id}.parts/1");

    app(UploadSessionManager::class)->abort($session->refresh());

    Storage::disk('local')->assertMissing("tmp/uploads/{$session->upload_id}.parts/1");
    expect($session->refresh()->status)->toBe(UploadStatus::Aborted);
});

it('dedup: a multipart assembly matching an earlier upload on the same disk yields one blob', function (): void {
    config()->set('vaulter-media.dedup.scope', 'drive');
    $bytes = 'shared multipart payload bytes';
    $storage = UploadTestHelpers::storage();

    foreach (['a.bin', 'b.bin'] as $name) {
        $session = initiateS3(totalBytes: strlen($bytes), name: $name, storage: $storage);
        $handler = app(S3MultipartHandler::class);
        $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor($bytes));
        $handler->complete($session->refresh());
        app(UploadSessionManager::class)->markAssembled($session->refresh());
        app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'test fixture'), session: $session->refresh());
    }

    // Two file nodes + two versions, but ONE physical blob (drive dedup).
    expect(FileRevision::query()->count())->toBe(2)
        ->and(Blob::query()->count())->toBe(1);
});

it('gates S3 initiation through the profile driver', function (bool $allow): void {
    $drive = UploadTestHelpers::storage();
    $this->actingAs(AccessTestHelpers::user());
    ProfileCreateGate::install($allow);
    $this->postJson('api/vaulter/v1/drives/'.$drive->id.'/uploads', [
        'name' => 'profile-s3.bin', 'total_size_bytes' => 5, 'protocol' => UploadProtocol::S3Multipart->value,
    ])->assertStatus($allow ? 201 : 403);
})->with([true, false]);

it('uses the existing direct multipart routes and publishes only the bytes that passed quarantine scanning', function (string $outcome): void {
    $bytes = 'direct multipart test';
    $identity = ['version' => 'version-1', 'etag' => '"quarantine-etag"'];
    $port = Mockery::mock(MultipartUploadPort::class);
    $port->shouldReceive('enabled')->andReturnTrue();
    $port->shouldReceive('initiate')->once()->with('local', Mockery::pattern('~^quarantine/[^/]+/[0-9a-f-]{36}$~'))
        ->andReturn('multipart-id');
    $port->shouldReceive('presignPart')->once()->with('local', Mockery::type('string'), 'multipart-id', 1, strlen($bytes), Mockery::type(DateTimeInterface::class))
        ->andReturn('https://s3.example.test/part');
    $port->shouldReceive('complete')->once()->with('local', Mockery::type('string'), 'multipart-id', strlen($bytes), Mockery::type('int'))
        ->andReturn($identity);
    $port->shouldReceive('open')->times($outcome === 'infected' ? 1 : 2)
        ->with('local', Mockery::type('string'), $identity)->andReturnUsing(function () use ($outcome, $bytes) {
            static $opens = 0;
            $stream = fopen('php://temp', 'r+b');
            fwrite($stream, ++$opens === 2 && $outcome === 'changed' ? str_repeat('x', strlen($bytes)) : $bytes);
            rewind($stream);

            return $stream;
        });
    if ($outcome !== 'changed') {
        $port->shouldReceive('discard')->once()->with('local', Mockery::type('string'), null, $identity);
    }
    app()->instance(MultipartUploadPort::class, $port);
    $scanner = new FakeContentScanner;
    if ($outcome === 'infected') {
        $scanner->alwaysReturns(new ScanResult(ScanVerdict::Infected));
    }
    app()->instance(ContentScanner::class, $scanner);
    config()->set([
        'queue.default' => 'quarantine-test',
        'queue.connections.quarantine-test' => ['driver' => 'database', 'retry_after' => 180],
        'vaulter-drive.scan.scanner_key' => 'fake',
        'vaulter-drive.scan.scanner_version' => '1',
    ]);
    Queue::fake();

    $drive = UploadTestHelpers::storage();
    $this->actingAs(AccessTestHelpers::user());
    $created = $this->postJson('api/vaulter/v1/drives/'.$drive->id.'/uploads', [
        'name' => 'direct-s3.bin', 'total_size_bytes' => strlen($bytes), 'protocol' => UploadProtocol::S3Multipart->value,
    ])->assertCreated();
    $upload = 'api/vaulter/v1/uploads/'.$created->json('data.upload_id');
    $this->postJson($upload.'/parts?part_no=1')->assertCreated()
        ->assertExactJson(['part_no' => 1, 'size' => strlen($bytes), 'url' => 'https://s3.example.test/part']);
    $this->postJson($upload.'/complete')->assertAccepted()->assertJsonPath('status', 'pending_scan');
    $this->postJson($upload.'/complete')->assertAccepted();
    expect(Blob::query()->count())->toBe(0);
    Queue::assertPushed(ScanJob::class, 1);
    app()->call([Queue::pushed(ScanJob::class)->first(), 'handle']);

    expect($scanner->scans[0]['bytes'])->toBe($bytes);
    $this->postJson($upload.'/complete')->assertStatus(match ($outcome) {
        'infected' => 422,
        'changed' => 409,
        default => 201,
    });
    expect(Blob::query()->count())->toBe($outcome === 'clean' ? 1 : 0);
})->with(['clean', 'infected', 'changed']);

it('uses presigned S3 part URLs and completes and deletes the exact quarantine version', function (): void {
    $key = 'quarantine/tenant/upload-id';
    $commands = [];
    $handler = new MockHandler;
    $capture = static function (CommandInterface $command, RequestInterface $request) use (&$commands): Result {
        $commands[] = [$command->getName(), $request];

        return match ($command->getName()) {
            'CreateMultipartUpload' => new Result(['UploadId' => 'server-upload-id']),
            'ListParts' => new Result(['Parts' => [['PartNumber' => 1, 'Size' => 5, 'ETag' => '"part-1"']]]),
            'CompleteMultipartUpload' => new Result(['VersionId' => 'object-version', 'ETag' => '"object-etag"']),
            'DeleteObject' => new Result,
        };
    };
    for ($i = 0; $i < 4; $i++) {
        $handler->append($capture);
    }
    Storage::set('direct-s3', Storage::build([
        'driver' => 's3', 'region' => 'us-east-1', 'bucket' => 'test-bucket',
        'credentials' => ['key' => 'test-access-key', 'secret' => 'test-secret'], 'handler' => $handler,
        'vaulter_s3_profile' => 'r2', 'vaulter_direct_multipart' => true,
    ]));

    $port = app(MultipartUploadPort::class);
    expect($port->enabled('direct-s3'))->toBeTrue();
    $id = $port->initiate('direct-s3', $key);
    $url = $port->presignPart('direct-s3', $key, $id, 1, 5, new DateTimeImmutable('+5 minutes'));
    expect($url)->toContain($key)->toContain('partNumber=1')->toContain('uploadId=server-upload-id')
        ->toContain('X-Amz-Signature=')->not->toContain('checksum');
    $identity = $port->complete('direct-s3', $key, $id, 5, 5_242_880);
    expect($identity)->toBe(['version' => 'object-version', 'etag' => '"object-etag"']);
    $port->discard('direct-s3', $key, null, $identity);

    expect(array_column($commands, 0))->toBe(['CreateMultipartUpload', 'ListParts', 'CompleteMultipartUpload', 'DeleteObject'])
        ->and($commands[2][1]->getHeaderLine('If-None-Match'))->toBe('*')
        ->and($commands[3][1]->getUri()->getQuery())->toBe('versionId=object-version');
});

it('gates the S3 parts controller through the profile driver', function (bool $allow): void {
    $session = initiateS3(totalBytes: 5, name: 'profile-parts.bin');
    $actor = AccessTestHelpers::user();
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['created_by' => $actor->getKey(), 'metadata' => ['actor_type' => $actor->getMorphClass()]])->save());
    $this->actingAs($actor);
    ProfileCreateGate::install($allow);
    $this->call('POST', 'api/vaulter/v1/uploads/'.$session->upload_id.'/parts',
        ['part_no' => 1, 'byte_offset' => 0], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], 'hello')
        ->assertStatus($allow ? 201 : 403);
})->with([true, false]);
