<?php

declare(strict_types=1);

namespace Vaulter\Testing\Concurrency;

use Closure;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;
use Packman\Attributes\Internal;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Real concurrency on PostgreSQL: every task runs in its OWN forked process on its
 * OWN database connection, and a file barrier releases all of them together.
 * SQLite serialises writes, so a race test there shows green on defective code —
 * a test built on this harness skips unless the connection is pgsql and the
 * pcntl + posix extensions are loaded.
 *
 * Fixtures the children must see have to be COMMITTED before run(): bind the test
 * directory with DatabaseMigrations, never RefreshDatabase (its wrapping
 * transaction is invisible to another connection).
 *
 * A task must return or throw, never exit(): the child would skip its result
 * (outcome ForkRace\MissingResult) and run PHPUnit's shutdown handlers.
 */
#[Internal]
final class ForkRace
{
    private const int BARRIER_STEP_US = 200;

    private const int PARENT_GRACE_MS = 5_000;

    public static function supported(?string $connection = null): bool
    {
        return DB::connection($connection)->getDriverName() === 'pgsql'
            && function_exists('pcntl_fork')
            && function_exists('posix_kill');
    }

    public static function skipUnlessSupported(TestCase $test, ?string $connection = null): void
    {
        if (!self::supported($connection)) {
            Assert::markTestSkipped('ForkRace needs a pgsql connection and the pcntl + posix extensions (SQLite serialises writes).');
        }
    }

    /**
     * @param  list<Closure(): mixed> $tasks
     * @param  list<string>           $connections connections each child re-opens; empty → the default one
     * @return list<RaceOutcome>
     */
    public static function run(array $tasks, array $connections = [], int $timeoutMs = 10_000): array
    {
        $names = $connections === [] ? [DB::getDefaultConnection()] : $connections;

        foreach ($names as $name) {
            $database = DB::connection($name)->getDatabaseName();

            if (!str_ends_with($database, '_test')) {
                throw new LogicException("ForkRace refuses to race on a non-test database [{$database}] (connection {$name}).");
            }
        }

        $dir = sys_get_temp_dir().'/vlt_race_'.getmypid().'_'.bin2hex(random_bytes(4));
        mkdir($dir);

        $count = count($tasks);
        $pids = [];

        try {
            foreach ($tasks as $i => $task) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    self::killAll($pids);

                    throw new RuntimeException('ForkRace: unable to fork a child process.');
                }

                if ($pid === 0) {
                    self::child($task, $i, $count, $names, $dir, $timeoutMs);
                }

                $pids[$i] = $pid;
            }

            $timedOut = self::waitAll($pids, $timeoutMs + self::PARENT_GRACE_MS);

            // The children closed the inherited PDO handles; never reuse them.
            foreach ($names as $name) {
                DB::purge($name);
                DB::reconnect($name);
            }

            $outcomes = [];

            for ($i = 0; $i < $count; $i++) {
                $outcomes[] = isset($timedOut[$i])
                    ? new RaceOutcome(false, null, 'ForkRace\\Timeout', 'timeout')
                    : self::readResult("{$dir}/result.{$i}");
            }

            $readyGlob = glob("{$dir}/ready.*");
            $ready = array_map(static fn (string $path): mixed => json_decode((string) file_get_contents($path), true), is_array($readyGlob) ? $readyGlob : []);
            fwrite(STDOUT, 'P38_FORK_PROOF '.json_encode(['barrier' => 'all ready files before task execution', 'participants' => $ready, 'timed_out' => array_keys($timedOut)]).PHP_EOL);

            return $outcomes;
        } finally {
            $cleanup = glob("{$dir}/*");
            array_map(unlink(...), is_array($cleanup) ? $cleanup : []);
            @rmdir($dir);
        }
    }

    /**
     * Runs in the forked child and never returns: a child that went back into
     * PHPUnit would duplicate the test output and run the teardown twice.
     *
     * @param Closure(): mixed $task
     * @param list<string>     $names
     */
    private static function child(Closure $task, int $index, int $count, array $names, string $dir, int $timeoutMs): never
    {
        try {
            foreach ($names as $name) {
                DB::purge($name);
                DB::reconnect($name);
            }

            $backends = [];
            foreach ($names as $name) {
                $connection = DB::connection($name);
                $row = $connection->selectOne('select pg_backend_pid() as pid');
                $backends[] = ['connection' => $name, 'database' => $connection->getDatabaseName(),
                    'backend_pid' => is_object($row) ? ($row->pid ?? null) : null];
            }
            file_put_contents("{$dir}/ready.{$index}", json_encode(['process_pid' => getmypid(), 'backends' => $backends]));

            $deadline = hrtime(true) + $timeoutMs * 1_000_000;

            while (count(is_array($readyFiles = glob("{$dir}/ready.*")) ? $readyFiles : []) < $count) {
                if (hrtime(true) >= $deadline) {
                    self::finish($dir, $index, ['ok' => false, 'value' => null, 'exception' => 'ForkRace\\BarrierTimeout', 'message' => 'barrier-timeout']);
                }

                usleep(self::BARRIER_STEP_US);
            }

            $value = $task();

            $result = self::isJsonValue($value)
                ? ['ok' => true, 'value' => $value, 'exception' => null, 'message' => null]
                : ['ok' => false, 'value' => null, 'exception' => 'ForkRace\\UnserializableValue', 'message' => get_debug_type($value)];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'value' => null, 'exception' => $e::class, 'message' => $e->getMessage()];
        }

        self::finish($dir, $index, $result);
    }

    /**
     * @param array{ok: bool, value: mixed, exception: ?string, message: ?string} $result
     */
    private static function finish(string $dir, int $index, array $result): never
    {
        try {
            // Invalid UTF-8 (e.g. a bytea binding in a QueryException message) is
            // substituted, so a failed task keeps its exception class.
            $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $e) {
            $encoded = json_encode(['ok' => false, 'value' => null, 'exception' => 'ForkRace\\UnserializableValue', 'message' => $e->getMessage()]);
            $json = is_string($encoded) ? $encoded : '';
        }

        file_put_contents("{$dir}/result.{$index}", $json);

        posix_kill((int) getmypid(), SIGKILL);

        exit(1); // unreachable: SIGKILL cannot be caught
    }

    /**
     * @param  array<int, int>  $pids
     * @return array<int, true> task indexes killed at the deadline
     */
    private static function waitAll(array $pids, int $deadlineMs): array
    {
        $deadline = hrtime(true) + $deadlineMs * 1_000_000;
        $pending = $pids;

        while ($pending !== [] && hrtime(true) < $deadline) {
            foreach ($pending as $i => $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                    unset($pending[$i]);
                }
            }

            if ($pending !== []) {
                usleep(1_000);
            }
        }

        self::killAll($pending);

        return array_fill_keys(array_keys($pending), true);
    }

    /**
     * @param array<int, int> $pids
     */
    private static function killAll(array $pids): void
    {
        foreach ($pids as $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    private static function readResult(string $path): RaceOutcome
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            return new RaceOutcome(false, null, 'ForkRace\\MissingResult', 'the child exited without writing a result');
        }

        /** @var array{ok: bool, value: mixed, exception: ?string, message: ?string} $data */
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return new RaceOutcome($data['ok'], $data['value'], $data['exception'], $data['message']);
    }

    private static function isJsonValue(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return true;
        }

        if (is_float($value)) {
            return is_finite($value);
        }

        if (is_array($value)) {
            return array_all($value, fn ($item): bool => self::isJsonValue($item));
        }

        return false;
    }
}
