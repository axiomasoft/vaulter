<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Uploads\Enums\UploadProtocol;
use Vaulter\Drive\Uploads\Enums\UploadStatus;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Drive\Uploads\Repositories\UploadSessionStoreRepository;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\Storage;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\TestCase;

/**
 * P1.4 — repro-набор для audit-item «Загрузки: tus и S3 multipart».
 * НЕ часть пакетного suite (D6): живёт в plans/AUDIT-VLT/artifacts/repro/P1.4/.
 * Доказывает вердикты C3 / C4 / C14 / C16 / C23 фактическим прогоном.
 */
uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function reproStorage(): Storage
{
    return Vaulter::for(DriveTestHelpers::host())->storage();
}

// ---------------------------------------------------------------------------
// C3 (U1.3/U2.4) — Upload-Offset != серверному → должно быть 409 Conflict.
// ---------------------------------------------------------------------------
it('C3: offset-mismatch PATCH возвращает НЕ 409 (доказать фактический статус)', function (): void {
    $storage = reproStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $uploadId = $this->postJson($base.'/resumable-uploads', [
        'protocol' => 'tus',
        'name' => 'c3.txt',
        'total_size_bytes' => 20,
    ])->json('data.upload_id');

    // Сервер на offset 0. Клиент заявляет offset 10 («прыжок вперёд», U2.4).
    $resp = $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '10', 'CONTENT_TYPE' => 'application/offset+octet-stream'],
        'XXXXXXXXXX',
    );

    fwrite(STDERR, "\n[C3] offset-mismatch PATCH status = ".$resp->getStatusCode()."\n");

    // tus требует 409. Фиксируем фактический код: ValidationException на не-JSON
    // запросе даёт 302-редирект (ещё хуже 422 — клиент-tus не восстановится).
    expect($resp->getStatusCode())->not->toBe(409);
    expect($resp->getStatusCode())->toBe(302);
});

// ---------------------------------------------------------------------------
// C2 (U1.2) — PATCH без Content-Type application/offset+octet-stream → 415.
// ---------------------------------------------------------------------------
it('C2: PATCH без offset+octet-stream Content-Type НЕ даёт 415', function (): void {
    $storage = reproStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $uploadId = $this->postJson($base.'/resumable-uploads', [
        'protocol' => 'tus',
        'name' => 'c2.txt',
        'total_size_bytes' => 20,
    ])->json('data.upload_id');

    $resp = $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'text/plain'],
        'XXXXXXXXXX',
    );

    fwrite(STDERR, "\n[C2] wrong-content-type PATCH status = ".$resp->getStatusCode()."\n");
    expect($resp->getStatusCode())->not->toBe(415);
});

// ---------------------------------------------------------------------------
// C4 (U2.1/U2.2) — read-modify-write offset БЕЗ row-lock → гонка теряет байты.
// Моделируем два конкурентных PATCH: оба прочитали offset=0 ДО коммита друг друга
// (именно то, что держат две параллельные HTTP-обработки), затем оба append(offset=0).
// ---------------------------------------------------------------------------
it('C4: конкурентные PATCH на одном offset затирают друг друга (нет блокировки)', function (): void {
    $storage = reproStorage();

    $session = UploadSession::query()->create([
        'storage_id' => $storage->getKey(),
        'upload_id' => (string) Illuminate\Support\Str::uuid(),
        'target_parent_id' => null,
        'target_name' => 'race.bin',
        'protocol' => UploadProtocol::Tus,
        'status' => UploadStatus::Initiated,
        'total_size_bytes' => 20,
        'received_bytes' => 0,
        'mime_type' => 'application/octet-stream',
    ]);

    $staged = app(StagedUpload::class);
    $sessions = app(UploadSessionStoreRepository::class);

    // Обе «параллельные» обработки читают серверный offset = 0.
    $offsetSeenByA = $staged->size($session); // 0
    $offsetSeenByB = $staged->size($session); // 0 — B прочитал ДО того как A записал
    expect($offsetSeenByA)->toBe(0)->and($offsetSeenByB)->toBe(0);

    // A аппендит 10 байт 'A' на offset 0.
    $streamA = fopen('php://temp', 'r+b');
    fwrite($streamA, str_repeat('A', 10));
    rewind($streamA);
    $sizeA = $staged->append(session: $session, chunk: $streamA, offset: $offsetSeenByA);
    $sessions->advanceReceived(session: $session, receivedBytes: $sizeA);

    // B (стартовал с того же offset 0) аппендит 10 байт 'B' на offset 0 —
    // StagedUpload::append берёт head=substr(existing,0,0)='' и перезаписывает файл.
    $streamB = fopen('php://temp', 'r+b');
    fwrite($streamB, str_repeat('B', 10));
    rewind($streamB);
    $sizeB = $staged->append(session: $session, chunk: $streamB, offset: $offsetSeenByB);
    $sessions->advanceReceived(session: $session, receivedBytes: $sizeB);

    $disk = $staged->diskFor($session);
    $content = (string) $disk->get($staged->keyFor($session));

    fwrite(STDERR, "\n[C4] staged content after two offset-0 patches = '".$content."' (len ".strlen($content).")\n");
    fwrite(STDERR, "[C4] received_bytes = ".$session->refresh()->received_bytes."\n");

    // Данные A ПОЛНОСТЬЮ потеряны — на диске только байты B. Ни блокировки, ни отказа.
    expect($content)->toBe(str_repeat('B', 10));
    expect($content)->not->toContain('A');
});

// ---------------------------------------------------------------------------
// C14 (U4.1/U4.2) — filename из метаданных НЕ участвует в построении пути;
// имя с разделителем пути отвергается.
// ---------------------------------------------------------------------------
it('C14: traversal в имени → 422; storage-key генерится сервером из upload_id', function (): void {
    $storage = reproStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    // Имя с разделителем пути должно отвергаться валидацией.
    $this->postJson($base.'/resumable-uploads', [
        'protocol' => 'tus',
        'name' => '../../../etc/passwd',
        'total_size_bytes' => 5,
    ])->assertStatus(422);

    // Валидное имя: staged-key детерминирован upload_id, а не именем/метаданными.
    $uploadId = $this->postJson($base.'/resumable-uploads', [
        'protocol' => 'tus',
        'name' => 'ok.txt',
        'total_size_bytes' => 5,
        'metadata' => ['filename' => '../../evil'],
    ])->json('data.upload_id');

    $session = UploadSession::query()->where('upload_id', $uploadId)->firstOrFail();
    $staged = app(StagedUpload::class);
    $key = $staged->keyFor($session);

    fwrite(STDERR, "\n[C14] staged key = ".$key."\n");
    expect($key)->toBe("tmp/uploads/{$uploadId}")
        ->and($key)->not->toContain('..')
        ->and($key)->not->toContain('evil');
});

// ---------------------------------------------------------------------------
// C16 (U5.1/U5.2) — sha256 пересчитывается сервером; клиентский expected_sha256
// не является основанием для commit.
// ---------------------------------------------------------------------------
it('C16: committed content_sha256 == server-recompute, НЕ клиентский hash', function (): void {
    $storage = reproStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $bytes = 'the real bytes that get hashed';
    $realSha = hash('sha256', $bytes);
    $fakeSha = str_repeat('0', 64);

    $uploadId = $this->postJson($base.'/resumable-uploads', [
        'protocol' => 'tus',
        'name' => 'c16.txt',
        'total_size_bytes' => strlen($bytes),
        'expected_sha256' => $fakeSha,
    ])->json('data.upload_id');

    $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$uploadId,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream'],
        $bytes,
    )->assertCreated();

    $version = FileVersion::query()->latest('id')->firstOrFail();
    fwrite(STDERR, "\n[C16] client fake sha = ".$fakeSha."\n[C16] stored content_sha256 = ".$version->content_sha256."\n[C16] real sha = ".$realSha."\n");

    expect($version->content_sha256)->toBe($realSha)
        ->and($version->content_sha256)->not->toBe($fakeSha);
});

// ---------------------------------------------------------------------------
// C23 — авторизация проверяется на КАЖДОМ запросе (create/HEAD/PATCH/DELETE).
// Под database-драйвером без грантов каждый endpoint должен давать 403.
// ---------------------------------------------------------------------------
it('C23: без гранта create/HEAD/PATCH/DELETE отклоняются (403)', function (): void {
    config()->set('vaulter.drivers.permission', 'database');
    $storage = reproStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    // create без CREATE-гранта → 403.
    $this->postJson($base.'/resumable-uploads', [
        'protocol' => 'tus',
        'name' => 'c23.txt',
        'total_size_bytes' => 10,
    ])->assertStatus(403);

    // Заведём сессию в обход авторизации, чтобы проверить остальные глаголы.
    $session = UploadSession::query()->create([
        'storage_id' => $storage->getKey(),
        'upload_id' => (string) Illuminate\Support\Str::uuid(),
        'target_parent_id' => null,
        'target_name' => 'c23.bin',
        'protocol' => UploadProtocol::Tus,
        'status' => UploadStatus::Initiated,
        'total_size_bytes' => 10,
        'received_bytes' => 0,
        'mime_type' => 'application/octet-stream',
    ]);
    $id = $session->upload_id;

    $head = $this->call('HEAD', 'api/vaulter/resumable-uploads/'.$id);
    $patch = $this->call(
        'PATCH',
        'api/vaulter/resumable-uploads/'.$id,
        [], [], [],
        ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream'],
        'XXXX',
    );
    $delete = $this->call('DELETE', 'api/vaulter/resumable-uploads/'.$id);

    fwrite(STDERR, "\n[C23] HEAD=".$head->getStatusCode()." PATCH=".$patch->getStatusCode()." DELETE=".$delete->getStatusCode()."\n");

    expect($head->getStatusCode())->toBe(403)
        ->and($patch->getStatusCode())->toBe(403)
        ->and($delete->getStatusCode())->toBe(403);
});

// ---------------------------------------------------------------------------
// C22 (U6.7) — S3 complete НЕ сверяет received_bytes с total_size_bytes:
// коммит проходит с неполным набором частей (усечённый файл).
// ---------------------------------------------------------------------------
it('C22: S3 complete коммитит усечённый файл (нет сверки total bytes)', function (): void {
    $storage = reproStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    // Объявлен total 1000, а загружена ОДНА часть в 10 байт.
    $uploadId = $this->postJson($base.'/resumable-uploads', [
        'protocol' => 's3-multipart',
        'name' => 'trunc.bin',
        'total_size_bytes' => 1000,
    ])->json('data.upload_id');

    $this->call(
        'POST',
        'api/vaulter/resumable-uploads/'.$uploadId.'/parts?part_no=1&byte_offset=0',
        [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], str_repeat('Z', 10),
    )->assertCreated();

    $resp = $this->postJson('api/vaulter/resumable-uploads/'.$uploadId.'/complete');

    fwrite(STDERR, "\n[C22] complete status = ".$resp->getStatusCode()." committed size = ".$resp->json('data.size_bytes')."\n");

    // Коммит проходит несмотря на 10 != 1000 → усечённый файл принят.
    $resp->assertCreated();
    expect($resp->json('data.size_bytes'))->toBe(10);
});
