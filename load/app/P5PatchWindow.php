<?php

namespace Vaulter\Load;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/** Load-only bounded pause after the real PATCH has acquired its advisory lock. */
final class P5PatchWindow
{
    public static function register(): void
    {
        DB::listen(static function (QueryExecuted $query): void {
            $console = app()->runningInConsole();
            $id = $console ? getenv('P5_SWEEP_TARGET') : request()->header('X-P5-Overlap', '');
            if (!$console && request()->method() !== 'PATCH') {
                return;
            }
            if (!preg_match('/^[0-9a-f-]{36}$/D', $id)
                || $query->sql !== 'SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS granted'
                || $query->bindings !== ['upload:'.$id]) {
                return;
            }
            $lock = $query->connection->selectOne(
                "SELECT pg_backend_pid() AS pid WHERE EXISTS (SELECT 1 FROM pg_locks WHERE pid = pg_backend_pid() AND locktype = 'advisory' AND granted)",
            );
            if ($console) {
                fwrite(STDERR, 'P5_SWEEP_TARGET|'.$id.'|'.($lock === null ? 'refused' : 'granted').'|END'.PHP_EOL);
                return;
            }
            if ($lock === null) {
                return;
            }
            $prefix = '/tmp/p5-overlap-'.$id;
            if (is_file($prefix.'.held')) {
                return;
            }
            file_put_contents($prefix.'.pending', json_encode(['upload_id' => $id, 'backend_pid' => $lock->pid]));
            rename($prefix.'.pending', $prefix.'.held');
            $deadline = microtime(true) + 45;
            while (!is_file($prefix.'.release')) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('P5 PATCH window release timed out');
                }
                usleep(50000);
                clearstatcache();
            }
        });
    }
}
