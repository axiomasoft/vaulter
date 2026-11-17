<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;

/*
 * Критическая секция upload-сессии обещает ОДНО на всех движках: «не смог взять лок» — это
 * ОТВЕТ, а не ожидание. PATCH превращает его в немедленный 409, sweeper — в «пропусти, сессия
 * живая» (тот же контракт записан в UPGRADE.md и в комментарии SweepUploadsCommand).
 *
 * На pgsql это держал pg_try_advisory_xact_lock, а на MySQL стоял ПРОСТОЙ блокирующий
 * lockForUpdate() — и контракт там молча ломался: sweeper не получал UploadConflictException,
 * а ВСТАВАЛ на row-локе идущего PATCH'а и на innodb_lock_wait_timeout ловил сырой
 * QueryException, которого SweepUploadsCommand не ждёт. Одна живая заливка роняла ВЕСЬ
 * плановый sweep, и остальные протухшие сессии оставались неубранными.
 *
 * MySQL-лейн в CI нет (сьют идёт на sqlite/pgsql), поэтому проверяем ровно то, что можно
 * проверить без сервера: SQL, который репозиторий выпускает на mysql-грамматике. pretend()
 * логирует запрос, не исполняя его.
 */
it('acquireForPatch на MySQL берёт НЕблокирующий лок — FOR UPDATE NOWAIT, R-02', function (string $driver): void {
    $default = (string) config('database.default');
    $pdo = DB::connection($default)->getPdo();

    config()->set("database.connections.{$driver}", [
        'driver' => $driver,
        'database' => 'vaulter_upload_lock_grammar_test',
        'prefix' => '',
    ]);
    // pretend() still quotes string bindings for its log. Use only the isolated test PDO for that escaping;
    // the connection keeps its MySQL/MariaDB grammar and executes no query.
    $connection = DB::connection($driver)->setPdo($pdo)->setReadPdo($pdo);
    // Репозиторий ветвится по драйверу ДЕФОЛТНОГО соединения — переключаем на время замера.
    config()->set('database.default', $driver);

    $model = app(DriveOptions::class)->model('upload_session');
    $session = new $model;
    $session->forceFill(['id' => 1, 'upload_id' => 'upload-1']);

    try {
        $queries = $connection->pretend(function () use ($session): void {
            try {
                app(UploadSessionStoreRepository::class)->acquireForPatch($session);
            } catch (ModelNotFoundException) {
                // pretend() не исполняет SELECT — строки не будет; нам нужен только SQL.
            }
        });
    } finally {
        // Обязательно ДО teardown: RefreshDatabase откатывает транзакцию на дефолтном
        // соединении по ИМЕНИ, и с mysql-дефолтом полез бы за живым сервером.
        config()->set('database.default', $default);
    }

    expect($queries)->not->toBeEmpty();

    // До фикса: «... for update» — блокирующий лок. Sweeper не получал конфликт, а ВСТАВАЛ
    // в очередь за row-локом идущего PATCH'а.
    expect(strtolower((string) $queries[0]['query']))->toContain('for update nowait');
})->with(['mysql', 'mariadb']);
