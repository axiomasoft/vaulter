<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\UploadConflictException;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Support\Uploads\StagedUpload;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Models\Drive\Node;

/**
 * VLT-REMED P5.2 / R-02 — гонка PATCH ↔ sweep. P0.3 закрыл гонку PATCH↔PATCH, но лок
 * сессии не покрывал уборщика: `SweepUploadsCommand` → `expire()` сносил staged-объект и
 * переводил сессию в Expired БЕЗ лока, параллельно с идущим PATCH. Итог (тот самый
 * Blocker-класс F-P1.4-01, только пара акторов другая): PATCH дописывал свой чанк в
 * пустоту (`append()` был fail-open — R-03), `advanceReceived()` форсил статус обратно в
 * Uploading (R-08) — терминальная сессия воскресала, первые N байт файла исчезали, а
 * клиент получал регрессировавший offset и дошлёт «хвост» в файл с дырой.
 *
 * SQLite сериализует записи и показал бы зелёное на дефектном коде (pre-mortem R4),
 * поэтому здесь — РЕАЛЬНАЯ конкуренция: два форкнутых процесса на PostgreSQL,
 * отпущенные общим барьером.
 *
 * Provisioning: DB_CONNECTION=pgsql (+ DB_HOST/PORT/DATABASE/USERNAME/PASSWORD) на сборке
 * PHP с pdo_pgsql и pcntl. Иначе — skip (лейн `drive-pgsql`, .github/workflows/drive.yml).
 */
it('PATCH и sweep одной сессии: победитель ровно один, файл не усечён, терминальная сессия не воскресает, R-02', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Гонка PATCH↔sweep наблюдаема только на PostgreSQL (SQLite сериализует записи).');
    }

    if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
        $this->markTestSkipped('Тесту конкурентности нужны расширения pcntl + posix.');
    }

    config()->set('vaulter.uploads.chunk_size', 256 * 1024 * 1024);
    config()->set('vaulter.uploads.max_bytes', 0);
    Storage::fake('local');

    // Чанк намеренно ТОЛСТЫЙ: append стримит prefix+chunk несколько сот миллисекунд, тогда
    // как expire() отрабатывает за единицы. Уборщик стартует с небольшой задержкой — так он
    // гарантированно попадает ВНУТРЬ окна PATCH (между чтением offset и записью байт), а не
    // до/после него. Без этого гонка просто не материализуется, и тест зелен даже на
    // дефектном коде — то есть НИЧЕГО не доказывает.
    $prefixLen = 16 * 1024 * 1024;
    $chunkLen = 64 * 1024 * 1024;
    $sweepDelayUs = 25_000;
    $prefix = str_repeat('A', $prefixLen);
    $chunk = str_repeat('B', $chunkLen);

    // Сессия с УЖЕ принятым префиксом и истёкшим TTL: она одновременно и валидный
    // кандидат для sweep, и активная загрузка, которую клиент продолжает.
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: $prefixLen + $chunkLen);

    app(StagedUpload::class)->append($session, UploadTestHelpers::streamFor($prefix), 0);
    app(UploadSessionStoreRepository::class)->advanceReceived($session, $prefixLen);
    $session->refresh()->forceFill(['expires_at' => Carbon::now()->subHour()])->save();

    $sessionKey = $session->getKey();

    $dir = sys_get_temp_dir().'/vlt_sweep_race_'.getmypid().'_'.bin2hex(random_bytes(4));
    mkdir($dir);

    $connection = DB::getDefaultConnection();
    $pids = [];

    // Актор 0 — клиентский PATCH (дошлёт свой чанк на offset=prefixLen).
    // Актор 1 — фоновый уборщик (vaulter:sweep-uploads по той же сессии).
    foreach ([0, 1] as $actor) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Не удалось форкнуть процесс для теста конкурентности.');
        }

        if ($pid === 0) {
            $outcome = 'error';

            try {
                DB::purge($connection);
                DB::reconnect($connection);

                // Барьер: оба актора стартуют одновременно, чтобы окна реально пересеклись.
                file_put_contents("$dir/ready.$actor", '1');
                $spins = 0;
                while (count(glob("$dir/ready.*") ?: []) < 2 && $spins++ < 50_000) {
                    usleep(200);
                }

                if ($actor === 0) {
                    app(TusUploadHandler::class)->patch(
                        UploadSession::query()->findOrFail($sessionKey),
                        UploadTestHelpers::streamFor($chunk),
                        $prefixLen,
                    );

                    $outcome = 'patch:ok';
                } else {
                    // Пусть PATCH успеет войти в критическую секцию и начать писать байты.
                    usleep($sweepDelayUs);

                    Artisan::call('vaulter:sweep-uploads');

                    $outcome = 'sweep:'.trim(Artisan::output());
                }
            } catch (Throwable $e) {
                $outcome = ($actor === 0 ? 'patch' : 'sweep').':rejected:'.class_basename($e);
            }

            file_put_contents("$dir/result.$actor", $outcome);

            posix_kill(getmypid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $patchResult = (string) @file_get_contents("$dir/result.0");
    $sweepResult = (string) @file_get_contents("$dir/result.1");

    DB::purge($connection);
    DB::reconnect($connection);

    $final = UploadSession::query()->findOrFail($sessionKey);
    $stagedSize = app(StagedUpload::class)->size($final);

    array_map('unlink', glob("$dir/*") ?: []);
    @rmdir($dir);

    $context = "patch: {$patchResult} | sweep: {$sweepResult} | status: {$final->status->value} | ".
        "received: {$final->received_bytes} | staged: {$stagedSize}";

    // Легальны РОВНО два исхода, и оба сохраняют целостность.
    if ($patchResult === 'patch:ok') {
        // (а) PATCH выиграл: файл цел (префикс + чанк), offset монотонно вырос, сессия
        //     осталась открытой; sweep её пропустил, а не снёс.
        expect($final->status)->toBe(UploadStatus::Uploading, $context)
            ->and($final->received_bytes)->toBe($prefixLen + $chunkLen, $context)
            ->and($stagedSize)->toBe($prefixLen + $chunkLen, $context);

        return;
    }

    // (б) sweep выиграл: сессия терминальна, staged снесён, PATCH отвергнут конфликтом.
    //     Дефектный код давал третий исход: Expired → снова Uploading, received_bytes
    //     РЕГРЕССИРОВАЛ до len(chunk), префикс файла потерян навсегда.
    expect($final->status)->toBe(UploadStatus::Expired, $context)
        ->and($stagedSize)->toBe(0, $context)
        ->and($final->received_bytes)->toBeLessThanOrEqual($prefixLen, $context)
        ->and($patchResult)->toStartWith('patch:rejected:', $context);
});

/**
 * VLT-REMED P5.2 / R-11 — commit-путь обязан входить в ТУ ЖЕ критическую секцию, что и
 * PATCH. До фикса `markAssembled()` + `CommitUploadAction::execute()` шли ВНЕ лока, а
 * `scopeSweepable` включает Assembled: уборщик брал лок беспрепятственно и сносил
 * staged-объект ПОСЕРЕДИНЕ стрима в BlobStore.commit — коммит либо падал на «нет
 * staged-байтов», либо фиксировал УСЕЧЁННЫЙ блоб (CAS честно перехэшировал обрезанные
 * байты, и поймать это было нечем).
 *
 * Наблюдаемость лока требует ДВУХ сеансов (advisory-лок реентрантен внутри одного) —
 * поэтому здесь второе независимое соединение, как в R-09, а не форк.
 */
it('commitAssembled() берёт ту же критическую секцию: пока лок сессии держит другой сеанс, коммит отбивается, R-11', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Неблокирующий лок сессии наблюдаем только на PostgreSQL (SQLite сериализует записи).');
    }

    Storage::fake('local');

    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 6, parentId: $storage->root_node_id);

    app(StagedUpload::class)->append($session, UploadTestHelpers::streamFor('abcdef'), 0);
    app(UploadSessionStoreRepository::class)->advanceReceived($session, 6);

    $default = DB::getDefaultConnection();
    config(['database.connections.vlt_commit_second' => config("database.connections.{$default}")]);

    DB::connection($default)->beginTransaction();

    try {
        // Сеанс A (уборщик/второй PATCH) держит лок сессии.
        app(UploadSessionStoreRepository::class)->acquireForPatch($session->refresh());

        DB::setDefaultConnection('vlt_commit_second');
        DB::connection('vlt_commit_second')->beginTransaction();

        try {
            // Сеанс B — контроллер, дошедший до assemble→commit. До фикса он не брал лок
            // вовсе и спокойно коммитил параллельно с реклеймом.
            expect(fn () => app(UploadSessionManager::class)->commitAssembled(
                $session->refresh(),
                static fn (UploadSession $locked): Node => throw new RuntimeException('коммит не должен идти без лока сессии'),
            ))->toThrow(UploadConflictException::class);
        } finally {
            DB::connection('vlt_commit_second')->rollBack();
            DB::setDefaultConnection($default);
            DB::purge('vlt_commit_second');
        }
    } finally {
        DB::connection($default)->rollBack();
    }
});

/**
 * VLT-REMED P5.2 / R-09 — ключ advisory-лока. `hashtext()` отдаёт int4, поэтому два
 * РАЗНЫХ upload_id делят один ключ лока с вероятностью дня рождения по 2^32 — и PATCH
 * невиновной сессии B получает ложный 409, потому что лок держит сессия A.
 *
 * Тест не «проверяет формулу», а ищет РЕАЛЬНУЮ коллизию int4 в самой PG и доказывает, что
 * два таких upload_id больше не мешают друг другу: лок берётся из ДВУХ независимых
 * соединений (advisory-лок реентрантен внутри одного сеанса — одного соединения тут мало).
 */
it('лок двух РАЗНЫХ upload_id, коллизирующих по 32-битному hashtext, выдаётся обоим, R-09', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Ключ advisory-лока существует только на PostgreSQL.');
    }

    // Настоящая коллизия hashtext (int4) на РЕАЛЬНЫХ upload_id (колонка — uuid), найденная
    // в самой PG: 300k проб по пространству 2^32 → ~10 коллизий по парадоксу дней рождения.
    $collision = DB::selectOne(<<<'SQL'
        WITH s AS (
            SELECT gen_random_uuid()::text AS id FROM generate_series(1, 300000)
        ), h AS (
            SELECT id, hashtext('upload:' || id) AS hv FROM s
        )
        SELECT (array_agg(id))[1] AS left_id, (array_agg(id))[2] AS right_id
        FROM h GROUP BY hv HAVING count(*) > 1 LIMIT 1
    SQL);

    expect($collision)->not->toBeNull('Не нашлось коллизии hashtext — увеличьте выборку.');

    /** @var object{left_id: string, right_id: string} $collision */
    $storage = UploadTestHelpers::storage();

    $left = UploadTestHelpers::session($storage);
    $left->forceFill(['upload_id' => $collision->left_id])->save();

    $right = UploadTestHelpers::session($storage);
    $right->forceFill(['upload_id' => $collision->right_id])->save();

    $repository = app(UploadSessionStoreRepository::class);
    $default = DB::getDefaultConnection();

    // Второе НЕЗАВИСИМОЕ соединение к той же БД: advisory-лок реентрантен внутри одного
    // сеанса, так что ложный конфликт наблюдаем только между двумя сеансами.
    config(['database.connections.vlt_second' => config("database.connections.{$default}")]);

    DB::connection($default)->beginTransaction();

    try {
        // Сеанс A держит лок сессии LEFT.
        $repository->acquireForPatch($left);

        DB::setDefaultConnection('vlt_second');
        DB::connection('vlt_second')->beginTransaction();

        try {
            // Сеанс B берёт лок сессии RIGHT. На 32-битном ключе (код ДО фикса) обе
            // сессии делят ключ → лок не выдан → UploadConflictException на невиновной
            // сессии. На 64-битном hashtextextended ключи различны → лок выдан.
            $locked = $repository->acquireForPatch($right);

            expect($locked->upload_id)->toBe($collision->right_id);
        } finally {
            DB::connection('vlt_second')->rollBack();
            DB::setDefaultConnection($default);
            DB::purge('vlt_second');
        }
    } finally {
        DB::connection($default)->rollBack();
    }
});
