<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage as StorageFacade;
use Illuminate\Validation\ValidationException;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * RV-03 — ревью P5.2: per-chunk hard cap на ПУТИ ДОСТАВКИ вреда (F-P1.4-04 / U3.3).
 *
 * Докблок StagedUpload::assertChunkWithinLimit() обещает отказ «BEFORE any bytes are staged»,
 * но барьер стоял ПОЗАДИ места вреда: контроллер сначала делал
 * `fwrite($stream, $request->getContent())` — всё тело PATCH материализовалось в PHP-строку, —
 * и только потом append() спрашивал про лимит. Плюс сам ключ был выключен по умолчанию
 * (max_chunk_bytes = 0) при max_bytes = 5 GiB: один авторизованный PATCH клал воркер по памяти.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function chunkLimitStorage(): Drive
{
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

it('ключ max_chunk_bytes имеет непустой дефолт — из коробки чанк ограничен, RV-03', function (): void {
    // Config-honesty: до фикса дефолт был 0 = «кап отключён», то есть обещанная защита из
    // коробки не стояла вовсе.
    expect(app(DriveConfig::class)->maxChunkSize())->toBeGreaterThan(0)
        ->and(config('vaulter-drive.uploads.resumable.max_chunk_size'))->toBeGreaterThan(0);
});

it('tus PATCH с Content-Length больше max_chunk_bytes отбивается ДО чтения тела, RV-03', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 8);

    $storage = chunkLimitStorage();
    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'big.bin',
        'total_size_bytes' => 100,
    ])->json('data.upload_id');

    $body = str_repeat('x', 64); // 64 > 8

    $this->call(
        'PATCH',
        'api/vaulter/v1/uploads/'.$uploadId,
        [], [], [],
        [
            'HTTP_UPLOAD_OFFSET' => '0',
            'CONTENT_TYPE' => 'application/offset+octet-stream',
            'HTTP_TUS_RESUMABLE' => '1.0.0',
            'CONTENT_LENGTH' => (string) strlen($body),
        ],
        $body,
    )->assertStatus(413);

    // Ни байта не осело на стадии.
    expect(StorageFacade::disk('local')->exists("tmp/uploads/{$uploadId}"))->toBeFalse();
});

it('S3 part с Content-Length больше max_chunk_bytes отбивается ДО чтения тела, RV-03', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 8);

    $storage = chunkLimitStorage();
    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'protocol' => 's3-multipart',
        'name' => 'big.bin',
        'total_size_bytes' => 100,
    ])->json('data.upload_id');

    $body = str_repeat('x', 64);

    $this->call(
        'POST',
        'api/vaulter/v1/uploads/'.$uploadId.'/parts?part_no=1&byte_offset=0',
        [], [], [],
        ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => (string) strlen($body)],
        $body,
    )->assertStatus(413);

    expect(StorageFacade::disk('local')->exists("tmp/uploads/{$uploadId}.parts/1"))->toBeFalse();
});

it('чанк без объявленной длины (нестатируемый поток, как php://input) всё равно ограничен, RV-03', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 8);

    $storage = chunkLimitStorage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 100);

    // Эмуляция php://input: сокет-пара нестатируема (fstat даёт size 0), ровно как тело
    // реального запроса. Проверка «по fstat» на таком потоке НЕ срабатывает — барьер обязан
    // стоять на самом копировании.
    [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    fwrite($writer, str_repeat('x', 64));
    fclose($writer);

    expect(fstat($reader)['size'])->toBe(0);

    expect(fn () => app(StagedUpload::class)->append($session, $reader, 0))
        ->toThrow(ValidationException::class);

    fclose($reader);

    expect(app(StagedUpload::class)->size($session->fresh()))->toBe(0);
});
