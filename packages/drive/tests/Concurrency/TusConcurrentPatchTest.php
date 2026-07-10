<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;

/**
 * The Blocker race (F-P1.4-01 / N3): two concurrent tus PATCH that read the SAME
 * server offset must NOT both append — that silently corrupts the assembled file
 * (the losing chunk's bytes vanish while received_bytes still advances). SQLite
 * serialises writes and would show green on the defective code (pre-mortem R4), so
 * this test drives REAL concurrency with two forked processes on PostgreSQL,
 * released together by a barrier. The fix (a row lock held over the whole
 * read-modify-write window) makes exactly one PATCH win; the other is rejected as a
 * conflict.
 *
 * Provisioning: DB_CONNECTION=pgsql (+ DB_HOST/PORT/DATABASE/USERNAME/PASSWORD) on a
 * PHP build with pdo_pgsql and pcntl. Skipped otherwise.
 */
it('два конкурентных PATCH с одинаковым offset — ровно один применяется, финальный sha256 эталонный, F-P1.4-01', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The concurrent-PATCH race is only observable on PostgreSQL (SQLite serialises writes).');
    }

    if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
        $this->markTestSkipped('The concurrency test needs the pcntl + posix extensions.');
    }

    // Allow large chunks so each PATCH's write is wide enough to genuinely overlap.
    config()->set('vaulter.uploads.chunk_size', 64 * 1024 * 1024);
    Storage::fake('local');

    $chunkLen = 16 * 1024 * 1024;
    $payloads = [str_repeat('A', $chunkLen), str_repeat('B', $chunkLen)];
    $reference = [hash('sha256', $payloads[0]), hash('sha256', $payloads[1])];

    // A committed session (DatabaseMigrations → no wrapping transaction) both forked
    // children can see on their own connection.
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: $chunkLen);
    $sessionKey = $session->getKey();

    $dir = sys_get_temp_dir().'/vlt_race_'.getmypid().'_'.bin2hex(random_bytes(4));
    mkdir($dir);

    $connection = DB::getDefaultConnection();
    $pids = [];

    foreach ($payloads as $i => $payload) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Unable to fork a child process for the concurrency test.');
        }

        if ($pid === 0) {
            // CHILD: take our OWN database connection (the inherited PDO must not be
            // shared), sync at the barrier, then fire the PATCH.
            $outcome = 'error';

            try {
                DB::purge($connection);
                DB::reconnect($connection);

                // Barrier: announce readiness, spin until both children are ready so
                // the two PATCH genuinely overlap.
                file_put_contents("$dir/ready.$i", '1');
                $spins = 0;
                while (count(glob("$dir/ready.*") ?: []) < 2 && $spins++ < 50_000) {
                    usleep(200);
                }

                app(TusUploadHandler::class)->patch(
                    UploadSession::query()->findOrFail($sessionKey),
                    UploadTestHelpers::streamFor($payload),
                    0,
                );

                $outcome = 'ok';
            } catch (Throwable $e) {
                $outcome = 'conflict:'.class_basename($e);
            }

            file_put_contents("$dir/result.$i", $outcome);

            // Hard-exit so PHPUnit's shutdown handlers do not run in the child (no
            // duplicate test output / teardown).
            posix_kill(getmypid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [
        (string) @file_get_contents("$dir/result.0"),
        (string) @file_get_contents("$dir/result.1"),
    ];

    $okCount = count(array_filter($results, static fn (string $r): bool => $r === 'ok'));

    // The parent's inherited connection may have been disturbed by the children; take
    // a fresh one before reading back the outcome.
    DB::purge($connection);
    DB::reconnect($connection);

    $final = UploadSession::query()->findOrFail($sessionKey);

    $staged = app(StagedUpload::class);
    $stream = $staged->readStream($final);
    $stagedSha = $stream !== null ? hashStream($stream) : null;

    // Cleanup.
    array_map('unlink', glob("$dir/*") ?: []);
    @rmdir($dir);

    // Exactly ONE PATCH applied (the other is a conflict); the pre-fix code lets both
    // win → okCount === 2.
    expect($okCount)->toBe(1, "results: {$results[0]} | {$results[1]}");

    // The staged bytes are exactly ONE untorn chunk, and received_bytes matches it.
    expect($final->received_bytes)->toBe($chunkLen)
        ->and($stagedSha)->toBeIn($reference);
});

/**
 * sha256 a stream without loading it whole into memory.
 *
 * @param resource $stream
 */
function hashStream(mixed $stream): string
{
    $ctx = hash_init('sha256');
    hash_update_stream($ctx, $stream);
    fclose($stream);

    return hash_final($ctx);
}
