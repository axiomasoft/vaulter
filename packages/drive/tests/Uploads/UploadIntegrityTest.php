<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Data\Snapshots\UploadSessionSnapshot;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Uploads\UploadContentMismatchException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\Uploads\UploadPartStoreRepository;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Media\Models\Cas\Blob;

// These tests drive handlers and repositories directly; Vaulter's entry points open the write scope, these calls do not.
beforeEach(fn () => DriveTestHelpers::insideOperation());

/**
 * Integrity gates on the resumable-upload commit path: the assembled object
 * must match the declared size and type, and a single PATCH chunk must be bounded
 * and streamed. These are the deterministic siblings of the concurrent-PATCH race
 * (PG-only) — see tests/Concurrency/TusConcurrentPatchTest.php.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('S3 complete with parts summing below total_size_bytes is refused and no blob is created', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session(
        $storage,
        protocol: UploadProtocol::S3Multipart,
        totalSizeBytes: 1000,
    );

    $handler = app(S3MultipartHandler::class);

    // Only 10 of the declared 1000 bytes are uploaded, then complete is called.
    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor(str_repeat('x', 10)));

    expect(fn () => $handler->complete($session->refresh()))
        ->toThrow(ValidationException::class);

    // The truncated object was never assembled/committed.
    expect(Blob::query()->count())->toBe(0);
});

it('refuses an S3 part larger than the declared size and the file ceiling before writing it', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, protocol: UploadProtocol::S3Multipart, totalSizeBytes: 3);
    $handler = app(S3MultipartHandler::class);
    $parts = app(UploadPartStoreRepository::class);

    expect(fn () => $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('abcd')))
        ->toThrow(ValidationException::class);
    expect($parts->sizeOf($session->refresh(), 1))->toBe(0);

    config()->set('vaulter-drive.uploads.max_file_size', 4);
    $cappedHandler = app(S3MultipartHandler::class);
    $capped = UploadTestHelpers::session($storage, protocol: UploadProtocol::S3Multipart, totalSizeBytes: 100);
    $cappedHandler->putPart($capped->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('ab'));
    $cappedHandler->putPart($capped->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('xy'));

    expect(fn () => $cappedHandler->putPart($capped->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('12345')))
        ->toThrow(ValidationException::class);
    expect($parts->sizeOf($capped->refresh(), 1))->toBe(2);
});

it('a tus PATCH beyond the declared length writes no bytes', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, protocol: UploadProtocol::Tus, totalSizeBytes: 3);
    $handler = app(TusUploadHandler::class);

    expect(fn () => $handler->patch($session->refresh(), UploadTestHelpers::streamFor('abcd'), 0))
        ->toThrow(ValidationException::class);
    expect(app(StagedUpload::class)->size($session->refresh()))->toBe(0);
});

it('an empty PATCH with a deferred length completes tus and allows commit', function (): void {
    $bytes = 'deferred upload';
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, protocol: UploadProtocol::Tus, targetName: 'deferred.bin');
    $handler = app(TusUploadHandler::class);
    $staged = app(StagedUpload::class);

    $partial = $handler->patch($session->refresh(), UploadTestHelpers::streamFor($bytes), 0);

    expect($partial)->toBe(['offset' => strlen($bytes), 'complete' => false])
        ->and($handler->isComplete(UploadSessionSnapshot::fromModel($session->refresh())))->toBeFalse()
        ->and($session->refresh()->total_size_bytes)->toBeNull()
        ->and($staged->size($session->refresh()))->toBe(strlen($bytes));

    $again = $handler->patch($session->refresh(), UploadTestHelpers::streamFor('more'), strlen($bytes));

    expect($again)->toBe(['offset' => strlen($bytes) + 4, 'complete' => false])
        ->and($handler->isComplete(UploadSessionSnapshot::fromModel($session->refresh())))->toBeFalse();

    $replay = $handler->patch($session->refresh(), UploadTestHelpers::streamFor(''), 0);

    expect($replay)->toBe(['offset' => strlen($bytes) + 4, 'complete' => false]);

    $done = $handler->patch($session->refresh(), UploadTestHelpers::streamFor(''), strlen($bytes) + 4);

    expect($done)->toBe(['offset' => strlen($bytes) + 4, 'complete' => true])
        ->and($handler->isComplete(UploadSessionSnapshot::fromModel($session->refresh())))->toBeTrue()
        ->and($staged->size($session->refresh()))->toBe(strlen($bytes) + 4)
        ->and($session->refresh()->total_size_bytes)->toBe(strlen($bytes) + 4);

    app(UploadSessionManager::class)->markAssembled($session->refresh());
    $node = app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'test fixture'), session: $session->refresh());

    expect($node->name)->toBe('deferred.bin')
        ->and($node->size_bytes)->toBe(strlen($bytes) + 4)
        ->and(Blob::query()->count())->toBe(1)
        ->and($session->refresh()->status)->toBe(UploadStatus::Committed);
});

it('an empty PATCH does not close tus until the declared length is reached', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, protocol: UploadProtocol::Tus, totalSizeBytes: 4);
    $handler = app(TusUploadHandler::class);

    $result = $handler->patch($session->refresh(), UploadTestHelpers::streamFor(''), 0);

    expect($result)->toBe(['offset' => 0, 'complete' => false])
        ->and($handler->isComplete(UploadSessionSnapshot::fromModel($session->refresh())))->toBeFalse()
        ->and($session->refresh()->total_size_bytes)->toBe(4);
});

it('a chunk above max_chunk_bytes (hard cap) is refused before any staged bytes are written', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 8); // 8-byte per-chunk hard ceiling

    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 100);

    $handler = app(TusUploadHandler::class);

    // 9 bytes > the 8-byte ceiling.
    expect(fn () => $handler->patch($session->refresh(), UploadTestHelpers::streamFor('123456789'), 0))
        ->toThrow(ValidationException::class);

    // Nothing landed on the staged temp — the cap is enforced before any write.
    expect(app(StagedUpload::class)->size($session->refresh()))->toBe(0);
});

it('a PATCH larger than chunk_size (only a HINT) is NOT rejected while max_chunk_bytes is unset', function (): void {
    // chunk_size is the advertised HINT, not a cap; the hard cap (max_chunk_bytes) is
    // disabled by default. A conformant tus client sending a body far larger than the
    // 8-byte hint in one PATCH must be ACCEPTED (the shipped contract: "the server
    // accepts any PATCH/part size up to the remaining bytes"). The pre-fix code read
    // the cap from chunk_size and rejected this — this asserts the divergence is gone.
    config()->set('vaulter-drive.uploads.resumable.chunk_size', 8);   // tiny HINT
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 0); // hard cap disabled (default)

    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 100);

    $handler = app(TusUploadHandler::class);

    $result = $handler->patch($session->refresh(), UploadTestHelpers::streamFor('123456789'), 0);

    // The 9-byte body landed in full — not rejected against the 8-byte hint.
    expect($result['offset'])->toBe(9)
        ->and(app(StagedUpload::class)->size($session->refresh()))->toBe(9);
});

it('append does not raise peak memory to the staged file size (streaming append)', function (): void {
    // A hard cap a fixed distance above what the process already holds: an absolute
    // value cannot be applied once the suite run itself has grown past it.
    ini_set('memory_limit', memory_get_usage(true) + 256 * 1024 * 1024);

    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage);

    $staged = app(StagedUpload::class);
    $key = $staged->keyFor($session->refresh());

    // Build a large staged file WITHOUT ever holding it in a PHP string: create the
    // key (so its dirs exist), then stream 96 MiB of 1-MiB blocks to the real path.
    $disk = Storage::disk('local');
    $disk->put($key, '');
    $path = $disk->path($key);
    $handle = fopen($path, 'wb');
    $block = str_repeat('x', 1024 * 1024);
    $blocks = 96;

    for ($i = 0; $i < $blocks; $i++) {
        fwrite($handle, $block);
    }

    fclose($handle);
    unset($block);
    gc_collect_cycles();

    $stagedBytes = $blocks * 1024 * 1024;

    // Appending a tiny tail at the authoritative offset must NOT load the 96 MiB
    // staged file into memory. The pre-fix code did $disk->get($key) — a full read
    // into a string — spiking peak memory by the file size.
    $before = memory_get_peak_usage(true);
    $staged->append($session->refresh(), UploadTestHelpers::streamFor('tail'), $stagedBytes);
    $after = memory_get_peak_usage(true);

    expect($after - $before)->toBeLessThan(24 * 1024 * 1024)
        ->and($staged->size($session->refresh()))->toBe($stagedBytes + 4);
});

it('commit rejects an object declared image/png with ZIP magic bytes', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['mime_type' => 'image/png'])->save());

    // A genuine ZIP archive (finfo reliably detects application/zip) declared as PNG.
    $zipPath = tempnam(sys_get_temp_dir(), 'vlt_zip_').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('inner.txt', 'not a png at all');
    $zip->close();
    $zipBytes = (string) file_get_contents($zipPath);
    @unlink($zipPath);

    app(TusUploadHandler::class)->patch($session->refresh(), UploadTestHelpers::streamFor($zipBytes), 0);
    app(UploadSessionManager::class)->markAssembled($session->refresh());

    expect(fn () => app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'test fixture'), session: $session->refresh()))
        ->toThrow(UploadContentMismatchException::class);

    // Nothing was committed — the type-confused object is refused before the blob.
    expect(Blob::query()->count())->toBe(0);
});
