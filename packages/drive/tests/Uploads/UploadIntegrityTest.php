<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Exceptions\Uploads\UploadContentMismatchException;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Models\Cas\Blob;
use Vaulter\Operations\OperationContext;

/**
 * Integrity gates on the resumable-upload commit path (W0/B5): the assembled object
 * must match the declared size and type, and a single PATCH chunk must be bounded
 * and streamed. These are the deterministic siblings of the concurrent-PATCH race
 * (F-P1.4-01, PG-only) — see tests/Concurrency/TusConcurrentPatchTest.php.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('S3 complete при сумме частей меньше total_size_bytes → отказ, блоб не создан, F-P1.4-03', function (): void {
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

it('чанк больше max_chunk_bytes (hard cap) → отказ до записи staged-байт, F-P1.4-04', function (): void {
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

it('PATCH крупнее chunk_size (это лишь HINT) НЕ отклоняется, пока max_chunk_bytes не задан, F-P1.4-04', function (): void {
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

it('append не поднимает пиковую память до размера staged-файла (потоковая дозапись), F-P1.4-04', function (): void {
    ini_set('memory_limit', '512M');

    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: null);

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

it('commit отклоняет объект с mime image/png и magic-bytes ZIP, F-P1.4-11', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: null);
    $session->forceFill(['mime_type' => 'image/png'])->save();

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

    expect(fn () => app(CompleteUploadAction::class)->execute(context: OperationContext::system($session->storage, 'test fixture'), session: $session->refresh()))
        ->toThrow(UploadContentMismatchException::class);

    // Nothing was committed — the type-confused object is refused before the blob.
    expect(Blob::query()->count())->toBe(0);
});
