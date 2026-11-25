<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Uploads\StagedUploadMissingException;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;

// These tests drive handlers and repositories directly; Vaulter's entry points open the write scope, these calls do not.
beforeEach(fn () => DriveTestHelpers::insideOperation());

/**
 * критическая секция upload-сессии. Это ДЕТЕРМИНИРОВАННЫЕ братья
 * форк-теста гонки PATCH↔sweep (tests/Concurrency/TusPatchVsSweepRaceTest.php, только
 * PG): здесь каждый механизм из цепочки порчи файла проверяется по отдельности, без
 * реальной конкуренции, поэтому они гоняются и в SQLite-лейне.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

/*
 * fail-CLOSED. До фикса append() переносил уже принятые байты только под
 * условием `if ($offset > 0 && $disk->exists($key))`: нет staged-объекта — ветка молча
 * пропускалась, и в ключ писался ОДИН новый чанк. Вызывающий принимал его длину за новый
 * authoritative offset, префикс файла исчезал навсегда. Путь целостности данных обязан
 * отказывать, а не усекать.
 */
it('append() at offset > 0 with the staged object gone REFUSES instead of truncating the file', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 20);

    $staged = app(StagedUpload::class);

    // Приняли 10 байт штатно.
    expect($staged->append($session, UploadTestHelpers::streamFor('0123456789'), 0))->toBe(10);

    // Кто-то снёс staged-объект (sweep/abort/ручная чистка бакета) — сессия всё ещё
    // считает, что у неё 10 байт.
    Storage::disk('local')->delete($staged->keyFor($session));

    expect(fn () => $staged->append($session, UploadTestHelpers::streamFor('ABCDE'), 10))
        ->toThrow(StagedUploadMissingException::class);

    // Ключевое: до фикса здесь лежали бы 5 байт 'ABCDE' и append вернул бы 5 —
    // регрессировавший offset. Сейчас байты не записаны вовсе.
    expect($staged->size($session->fresh()))->toBe(0);
});

/*
 * advanceReceived() форсил `status => Uploading` безусловно, минуя
 * UploadStatus::canTransitionTo, и был механизмом ВОСКРЕШЕНИЯ терминальной сессии в
 * гонке PATCH ↔ sweep. Compare-and-set по открытым статусам: 0 затронутых строк → конфликт.
 */
it('advanceReceived() does NOT resurrect a terminal session (CAS on the open statuses)', function (UploadStatus $terminal): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 100);

    // Сессия ушла в терминальный статус (sweep/abort/commit) — как в гонке PATCH ↔ sweep.
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['status' => $terminal])->save());

    expect(fn () => app(UploadSessionStoreRepository::class)->advanceReceived($session, 42))
        ->toThrow(UploadConflictException::class);

    $after = UploadSession::query()->findOrFail($session->getKey());

    // До фикса: status → uploading, received_bytes → 42. Терминальная сессия воскресла.
    expect($after->status)->toBe($terminal)
        ->and($after->received_bytes)->toBe(0);
})->with([
    'expired' => [UploadStatus::Expired],
    'aborted' => [UploadStatus::Aborted],
    'committed' => [UploadStatus::Committed],
]);

/*
 * Детерминированная половина гонки PATCH ↔ sweep: expire()/abort() обязаны входить в ту же
 * критическую секцию, что и PATCH, и ПЕРЕЧИТЫВАТЬ статус под локом. Здесь проверяется
 * второй инвариант: сессия, уже ушедшая в терминал между выборкой кандидата и локом, НЕ
 * трогается повторно — байты чужой (новой) стадии не сносятся, событие не дублируется.
 */
it('expire() rereads the status under the lock and leaves an already terminal session alone', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 100);

    // Кандидат, выбранный sweep'ом ДО того, как клиент сам её оборвал.
    $stale = UploadSession::query()->findOrFail($session->getKey());

    app(UploadSessionManager::class)->abort($session);
    expect(UploadSession::query()->findOrFail($session->getKey())->status)->toBe(UploadStatus::Aborted);

    // Sweep приходит со СТАРЫМ снимком: статус перечитывается под локом → no-op,
    // сессия остаётся Aborted (а не переписывается в Expired).
    $result = app(UploadSessionManager::class)->expire($stale);

    expect($result->status)->toBe(UploadStatus::Aborted)
        ->and(UploadSession::query()->findOrFail($session->getKey())->status)->toBe(UploadStatus::Aborted);
});

/*
 * Sweep обязан сносить именно СТУХШИЕ сессии. Санити на не-конфликтном пути
 * (пропуск залоченной сессии требует реального второго соединения — он в
 * tests/Concurrency/TusPatchVsSweepRaceTest.php, PG-лейн).
 */
it('vaulter:uploads:sweep reclaims a stale session and cleans the staged bytes', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 100);

    app(StagedUpload::class)->append($session, UploadTestHelpers::streamFor('0123456789'), 0);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['expires_at' => Carbon::now()->subHour()])->save());

    $this->artisan('vaulter:uploads:sweep')->assertSuccessful();

    $after = UploadSession::query()->findOrFail($session->getKey());

    expect($after->status)->toBe(UploadStatus::Expired)
        ->and(app(StagedUpload::class)->size($after))->toBe(0);
});

/*
 * markCommitted() был ЕДИНСТВЕННЫМ писателем статуса, которому не досталось
 * compare-and-set: голый forceFill('committed'). Пока commit-путь шёл ВНЕ
 * критической секции, sweep успевал реклеймить Assembled-сессию (scopeSweepable её
 * включает) прямо во время коммита — и markCommitted воскрешал терминальную строку в
 * Committed, дорисовывая ей assembled_blob_id. CAS по Assembled: 0 строк → конфликт.
 */
it('markCommitted() does NOT resurrect a reclaimed session (CAS on Assembled)', function (UploadStatus $terminal): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 10);

    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['status' => $terminal])->save());

    expect(fn () => app(UploadSessionStoreRepository::class)->markCommitted($session, 777))
        ->toThrow(UploadConflictException::class);

    $after = UploadSession::query()->findOrFail($session->getKey());

    // До фикса: status → committed, assembled_blob_id → 777. Сессия, у которой sweep уже
    // снёс staged-байты, объявлялась успешно закоммиченной.
    expect($after->status)->toBe($terminal)
        ->and($after->assembled_blob_id)->toBeNull();
})->with([
    'expired' => [UploadStatus::Expired],
    'aborted' => [UploadStatus::Aborted],
]);

/*
 * Сквозной случай: сессия, реклеймленная ПОКА её байты ещё лежат на стадии (ровно окно
 * между markAssembled и BlobStore.commit), не должна закоммититься. До фикса
 * CommitUploadAction спокойно доводил её до конца: узел создавался, а терминальная строка
 * воскресала в Committed — sweep и commit молча делили одни и те же байты.
 */
it('committing a reclaimed session is rejected: no node is created and the status is not resurrected', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 6, parentId: $storage->root_node_id);

    app(StagedUpload::class)->append($session, UploadTestHelpers::streamFor('abcdef'), 0);
    app(UploadSessionStoreRepository::class)->advanceReceived($session, 6);
    app(UploadSessionManager::class)->markAssembled($session->refresh());

    $nodesBefore = Node::query()->count();

    // Sweep успел перевести сессию в терминал, но staged-объект ещё читается (discard и
    // commit — одно и то же окно; на реальной гонке байты исчезают ПОСЕРЕДИНЕ стрима).
    UploadSession::query()->whereKey($session->getKey())->update(['status' => UploadStatus::Expired->value]);

    expect(fn () => app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'test fixture'), session: $session->refresh()))
        ->toThrow(UploadConflictException::class);

    $after = UploadSession::query()->findOrFail($session->getKey());

    expect($after->status)->toBe(UploadStatus::Expired)
        ->and($after->assembled_blob_id)->toBeNull()
        ->and(Node::query()->count())->toBe($nodesBefore);
});

/*
 * commitAssembled() (шов, которым контроллер загоняет assemble→commit в ТУ ЖЕ
 * критическую секцию, что и PATCH) обязан перечитывать статус под локом: сессия, ушедшая
 * в терминал между последним чанком и коммитом, отбивается конфликтом, а сам коммит не
 * вызывается вовсе.
 */
it('commitAssembled() rereads the status under the lock and does not commit a terminal session', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 6, parentId: $storage->root_node_id);

    app(StagedUpload::class)->append($session, UploadTestHelpers::streamFor('abcdef'), 0);
    app(UploadSessionStoreRepository::class)->advanceReceived($session, 6);

    // Снимок, с которым контроллер пришёл коммитить; строка уже реклеймлена уборщиком.
    $stale = UploadSession::query()->findOrFail($session->getKey());
    app(UploadSessionManager::class)->expire($session->refresh());

    expect(fn () => app(UploadSessionManager::class)->commitAssembled(
        $stale,
        static fn (UploadSession $locked): Node => throw new RuntimeException('коммит терминальной сессии не должен быть вызван'),
    ))->toThrow(UploadConflictException::class);

    expect(UploadSession::query()->findOrFail($session->getKey())->status)->toBe(UploadStatus::Expired);
});

/*
 * putPart() (S3-multipart) остался ВНЕ критической секции: он не брал лок и проверял
 * isOpen() на снимке, прочитанном контроллером ДО авторизации. Сессия, реклеймленная между
 * этим чтением и записью части, проходила проверку по УСТАРЕВШЕЙ модели, и part-объект
 * ложился на диск уже терминальной сессии: scopeSweepable её больше не выберет → байты
 * осиротели навсегда. Тот же класс гонки, что закрыт для PATCH/expire/abort/commit.
 */
it('putPart() rereads the status under the lock: a part of a terminal session is not written', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, protocol: UploadProtocol::S3Multipart, totalSizeBytes: 100);

    // Снимок, с которым контроллер пришёл писать часть.
    $stale = UploadSession::query()->findOrFail($session->getKey());

    // Пока часть летела по сети, sweep реклеймил сессию: staged-байты снесены, статус терминальный.
    app(UploadSessionManager::class)->expire($session->refresh());

    expect(fn () => app(S3MultipartHandler::class)->putPart(
        $stale,
        partNo: 1,
        byteOffset: 0,
        chunk: UploadTestHelpers::streamFor('0123456789'),
    ))->toThrow(ValidationException::class);

    // До фикса: part-строка и part-объект на диске у Expired-сессии — осиротевшие байты.
    expect(UploadPart::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists("tmp/uploads/{$stale->upload_id}.parts/1"))->toBeFalse()
        ->and(UploadSession::query()->findOrFail($session->getKey())->status)->toBe(UploadStatus::Expired);
});

/*
 * Санити: штатный PATCH после фикса по-прежнему работает (CAS пропускает открытую
 * сессию, append находит staged-объект) — фикс не сломал happy-path.
 */
it('the regular PATCH sequence still works', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 6);

    $handler = app(TusUploadHandler::class);

    expect($handler->patch($session->fresh(), UploadTestHelpers::streamFor('abc'), 0)['offset'])->toBe(3);

    $result = $handler->patch($session->fresh(), UploadTestHelpers::streamFor('def'), 3);

    expect($result)->toBe(['offset' => 6, 'complete' => true])
        ->and(UploadSession::query()->findOrFail($session->getKey())->status)->toBe(UploadStatus::Uploading);
});
