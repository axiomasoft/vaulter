<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;

/**
 * The PATCH ↔ sweep race. The offset lock closed the PATCH↔PATCH race, but the session
 * lock did not cover the sweeper: `SweepUploadsCommand` → `expire()` removed the staged object and
 * moved the session to Expired WITHOUT a lock, in parallel with a running PATCH. The result (the
 * same class of bug, only with a different pair of actors): PATCH appended its chunk into
 * nothing (`append()` failed open), `advanceReceived()` forced the status back to
 * Uploading, so the terminal session came back to life, the first N bytes of the file vanished, and
 * the client got a regressed offset and would send the "tail" into a file with a hole.
 *
 * SQLite serializes writes and would show green on the broken code, so this
 * test uses REAL concurrency: two forked processes on PostgreSQL,
 * released by a shared barrier.
 *
 * Provisioning: DB_CONNECTION=pgsql (+ DB_HOST/PORT/DATABASE/USERNAME/PASSWORD) on a PHP
 * build with pdo_pgsql and pcntl. Otherwise skipped (the PostgreSQL lane of .github/workflows/ci.yml).
 */
it('PATCH and sweep of one session: exactly one wins, the file is not truncated, a terminal session does not come back', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The PATCH↔sweep race is observable only on PostgreSQL (SQLite serializes writes).');
    }

    if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
        $this->markTestSkipped('The concurrency test needs the pcntl and posix extensions.');
    }

    config()->set('vaulter-drive.uploads.resumable.chunk_size', 256 * 1024 * 1024);
    config()->set('vaulter-drive.uploads.max_file_size', 0);
    Storage::fake('local');

    // The chunk is deliberately LARGE: append streams prefix+chunk for several hundred milliseconds,
    // while expire() takes a few. The sweeper starts after a small delay so that it
    // reliably lands INSIDE the PATCH window (between reading the offset and writing the bytes) rather
    // than before or after it. Without this the race never materializes and the test is green even on
    // the broken code, i.e. it proves NOTHING.
    $prefixLen = 16 * 1024 * 1024;
    $chunkLen = 64 * 1024 * 1024;
    $sweepDelayUs = 25_000;
    $prefix = str_repeat('A', $prefixLen);
    $chunk = str_repeat('B', $chunkLen);

    // A session with an ALREADY accepted prefix and an expired TTL: it is at once a valid
    // sweep candidate and an active upload the client keeps going.
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

    // Actor 0: the client PATCH (sends its chunk at offset=prefixLen).
    // Actor 1: the background sweeper (vaulter:uploads:sweep over the same session).
    foreach ([0, 1] as $actor) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Could not fork a process for the concurrency test.');
        }

        if ($pid === 0) {
            $outcome = 'error';

            try {
                DB::purge($connection);
                DB::reconnect($connection);

                // Barrier: both actors start at once so the windows really overlap.
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
                    // Let PATCH enter the critical section and start writing bytes.
                    usleep($sweepDelayUs);

                    Artisan::call('vaulter:uploads:sweep');

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

    array_map(unlink(...), glob("$dir/*") ?: []);
    @rmdir($dir);

    $context = "patch: {$patchResult} | sweep: {$sweepResult} | status: {$final->status->value} | ".
        "received: {$final->received_bytes} | staged: {$stagedSize}";

    // EXACTLY two outcomes are legal, and both keep integrity.
    if ($patchResult === 'patch:ok') {
        // (a) PATCH won: the file is intact (prefix + chunk), the offset grew monotonically, the session
        //     stayed open; the sweep skipped it instead of removing it.
        expect($final->status)->toBe(UploadStatus::Uploading, $context)
            ->and($final->received_bytes)->toBe($prefixLen + $chunkLen, $context)
            ->and($stagedSize)->toBe($prefixLen + $chunkLen, $context);

        return;
    }

    // (b) sweep won: the session is terminal, staged bytes removed, PATCH rejected with a conflict.
    //     The broken code produced a third outcome: Expired → Uploading again, received_bytes
    //     REGRESSED to len(chunk), and the file prefix was lost for good.
    expect($final->status)->toBe(UploadStatus::Expired, $context)
        ->and($stagedSize)->toBe(0, $context)
        ->and($final->received_bytes)->toBeLessThanOrEqual($prefixLen, $context)
        ->and($patchResult)->toStartWith('patch:rejected:', $context);
});

/**
 * The commit path must enter THE SAME critical section as
 * PATCH. Before the fix `markAssembled()` + `CommitUploadAction::execute()` ran OUTSIDE the lock, and
 * `scopeSweepable` includes Assembled: the sweeper took the lock unhindered and removed the
 * staged object in the MIDDLE of the stream into BlobStore.commit, so the commit either failed on "no
 * staged bytes" or recorded a TRUNCATED blob (CAS honestly rehashed the cut
 * bytes, and nothing could catch it).
 *
 * Observing the lock takes TWO sessions (an advisory lock is reentrant within one),
 * hence a second independent connection here instead of a fork.
 */
it('commitAssembled() takes the same critical section: while another session holds the lock, the commit is rejected', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The non-blocking session lock is observable only on PostgreSQL (SQLite serializes writes).');
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
        // Session A (the sweeper or a second PATCH) holds the session lock.
        app(UploadSessionStoreRepository::class)->acquireForPatch($session->refresh());

        DB::setDefaultConnection('vlt_commit_second');
        DB::connection('vlt_commit_second')->beginTransaction();

        try {
            // Session B: the controller that reached assemble→commit. Before the fix it took no lock
            // at all and committed calmly in parallel with the reclaim.
            expect(fn () => app(UploadSessionManager::class)->commitAssembled(
                $session->refresh(),
                static fn (UploadSession $locked): Node => throw new RuntimeException('the commit must not run without the session lock'),
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
 * The advisory lock key. `hashtext()` returns int4, so two
 * DIFFERENT upload_ids share one lock key with birthday probability over 2^32, and the PATCH of
 * an innocent session B gets a false 409 because session A holds the lock.
 *
 * The test does not "check the formula": it finds a REAL int4 collision in PostgreSQL itself and proves that
 * two such upload_ids no longer interfere: the lock is taken from TWO independent
 * connections (an advisory lock is reentrant within one session, so one connection is not enough).
 */
it('locks for two DIFFERENT upload_ids that collide on 32-bit hashtext are granted to both', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The advisory lock key exists only on PostgreSQL.');
    }

    // A real hashtext (int4) collision on REAL upload_ids (the column is uuid), found
    // in PostgreSQL itself: 300k samples over a 2^32 space give ~10 collisions by the birthday paradox.
    $collision = DB::selectOne(<<<'SQL'
        WITH s AS (
            SELECT md5(i::text)::uuid::text AS id FROM generate_series(1, 300000) AS probe(i)
        ), h AS (
            SELECT id, hashtext('upload:' || id) AS hv FROM s
        )
        SELECT (array_agg(id))[1] AS left_id, (array_agg(id))[2] AS right_id
        FROM h GROUP BY hv HAVING count(*) > 1 ORDER BY hv LIMIT 1
    SQL);

    expect($collision)->not->toBeNull('No hashtext collision found; increase the sample.');

    /** @var object{left_id: string, right_id: string} $collision */
    $storage = UploadTestHelpers::storage();

    $left = UploadTestHelpers::session($storage);
    $left->forceFill(['upload_id' => $collision->left_id])->save();

    $right = UploadTestHelpers::session($storage);
    $right->forceFill(['upload_id' => $collision->right_id])->save();

    $repository = app(UploadSessionStoreRepository::class);
    $default = DB::getDefaultConnection();

    // A second INDEPENDENT connection to the same database: an advisory lock is reentrant within one
    // session, so a false conflict is observable only between two sessions.
    config(['database.connections.vlt_second' => config("database.connections.{$default}")]);

    DB::connection($default)->beginTransaction();

    try {
        // Session A holds the lock of session LEFT.
        $repository->acquireForPatch($left);

        DB::setDefaultConnection('vlt_second');
        DB::connection('vlt_second')->beginTransaction();

        try {
            // Session B takes the lock of session RIGHT. With a 32-bit key (the code BEFORE the fix) both
            // sessions share the key, so the lock is not granted and the innocent session gets an
            // UploadConflictException. With the 64-bit hashtextextended the keys differ and the lock is granted.
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
