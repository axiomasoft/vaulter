<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;

/*
 * The upload-session critical section promises ONE thing on every engine: "could not take the lock" is
 * an ANSWER, not a wait. PATCH turns it into an immediate 409, the sweeper into "skip it, the session
 * is alive" (the same contract is written in UPGRADE.md and in the SweepUploadsCommand comment).
 *
 * On pgsql pg_try_advisory_xact_lock kept that promise, while MySQL used a PLAIN blocking
 * lockForUpdate(), and the contract silently broke there: the sweeper got no UploadConflictException
 * but WAITED on the row lock of a running PATCH and, at innodb_lock_wait_timeout, caught a raw
 * QueryException that SweepUploadsCommand does not expect. One live upload took the WHOLE
 * scheduled sweep down, and the other stale sessions stayed uncleaned.
 *
 * CI has no MySQL lane for this suite (it runs on sqlite/pgsql), so the test checks exactly what can
 * be checked without a server: the SQL the repository emits with the mysql grammar. pretend()
 * logs the query without executing it.
 */
it('acquireForPatch on MySQL takes a NON-blocking lock (FOR UPDATE NOWAIT)', function (string $driver): void {
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
    // The repository branches on the driver of the DEFAULT connection, so switch it for the measurement.
    config()->set('database.default', $driver);

    $model = app(DriveOptions::class)->model('upload_session');
    $session = new $model;
    $session->forceFill(['id' => 1, 'upload_id' => 'upload-1']);

    try {
        $queries = $connection->pretend(function () use ($session): void {
            try {
                app(UploadSessionStoreRepository::class)->acquireForPatch($session);
            } catch (ModelNotFoundException) {
                // pretend() does not execute the SELECT, so there is no row; only the SQL matters.
            }
        });
    } finally {
        // This must happen BEFORE teardown: RefreshDatabase rolls back the transaction on the default
        // connection by NAME, and with a mysql default it would reach for a live server.
        config()->set('database.default', $default);
    }

    expect($queries)->not->toBeEmpty();

    // Before the fix: "... for update", a blocking lock. The sweeper got no conflict and instead
    // QUEUED behind the row lock of the running PATCH.
    expect(strtolower((string) $queries[0]['query']))->toContain('for update nowait');
})->with(['mysql', 'mariadb']);
