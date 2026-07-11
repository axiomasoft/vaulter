<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Exceptions\StagedUploadMissingException;
use Vaulter\Drive\Exceptions\UploadConflictException;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\Enums\UploadStatus;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Drive\Uploads\Repositories\UploadSessionStoreRepository;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;

/**
 * VLT-REMED P5.2 — критическая секция upload-сессии. Это ДЕТЕРМИНИРОВАННЫЕ братья
 * форк-теста гонки PATCH↔sweep (tests/Concurrency/TusPatchVsSweepRaceTest.php, только
 * PG): здесь каждый механизм из цепочки порчи файла проверяется по отдельности, без
 * реальной конкуренции, поэтому они гоняются и в SQLite-лейне.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

/*
 * R-03 — fail-CLOSED. До фикса append() переносил уже принятые байты только под
 * условием `if ($offset > 0 && $disk->exists($key))`: нет staged-объекта — ветка молча
 * пропускалась, и в ключ писался ОДИН новый чанк. Вызывающий принимал его длину за новый
 * authoritative offset, префикс файла исчезал навсегда. Путь целостности данных обязан
 * отказывать, а не усекать.
 */
it('append() при offset > 0 и пропавшем staged-объекте ОТКАЗЫВАЕТ, а не усекает файл, R-03', function (): void {
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
 * R-08 — advanceReceived() форсил `status => Uploading` безусловно, минуя
 * UploadStatus::canTransitionTo, и был механизмом ВОСКРЕШЕНИЯ терминальной сессии в
 * гонке R-02. Compare-and-set по открытым статусам: 0 затронутых строк → конфликт.
 */
it('advanceReceived() НЕ воскрешает терминальную сессию — CAS по открытым статусам, R-08', function (UploadStatus $terminal): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 100);

    // Сессия ушла в терминальный статус (sweep/abort/commit) — как в гонке R-02.
    $session->forceFill(['status' => $terminal])->save();

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
 * R-02 (детерминированная половина) — expire()/abort() обязаны входить в ту же
 * критическую секцию, что и PATCH, и ПЕРЕЧИТЫВАТЬ статус под локом. Здесь проверяется
 * второй инвариант: сессия, уже ушедшая в терминал между выборкой кандидата и локом, НЕ
 * трогается повторно — байты чужой (новой) стадии не сносятся, событие не дублируется.
 */
it('expire() перечитывает статус под локом и не трогает уже терминальную сессию, R-02', function (): void {
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
 * R-02 — sweep обязан сносить именно СТУХШИЕ сессии. Санити на не-конфликтном пути
 * (пропуск залоченной сессии требует реального второго соединения — он в
 * tests/Concurrency/TusPatchVsSweepRaceTest.php, PG-лейн).
 */
it('vaulter:sweep-uploads реклеймит стухшую сессию и чистит staged, R-02', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 100);

    app(StagedUpload::class)->append($session, UploadTestHelpers::streamFor('0123456789'), 0);
    $session->forceFill(['expires_at' => Carbon::now()->subHour()])->save();

    $this->artisan('vaulter:sweep-uploads')->assertSuccessful();

    $after = UploadSession::query()->findOrFail($session->getKey());

    expect($after->status)->toBe(UploadStatus::Expired)
        ->and(app(StagedUpload::class)->size($after))->toBe(0);
});

/*
 * Санити: штатный PATCH после фикса по-прежнему работает (CAS пропускает открытую
 * сессию, append находит staged-объект) — фикс не сломал happy-path.
 */
it('штатная последовательность PATCH после фикса не сломана', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 6);

    $handler = app(TusUploadHandler::class);

    expect($handler->patch($session->fresh(), UploadTestHelpers::streamFor('abc'), 0)['offset'])->toBe(3);

    $result = $handler->patch($session->fresh(), UploadTestHelpers::streamFor('def'), 3);

    expect($result)->toBe(['offset' => 6, 'complete' => true])
        ->and(UploadSession::query()->findOrFail($session->getKey())->status)->toBe(UploadStatus::Uploading);
});
