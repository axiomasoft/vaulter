<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;

/**
 * The harness proves itself: a locked read-modify-write serialises, the same code
 * WITHOUT the lock loses an update (the children really overlap), every child holds
 * its own backend, and a throwing task is reported without sinking its sibling.
 *
 * Provisioning: DB_CONNECTION=pgsql (+ DB_HOST/PORT/DATABASE/USERNAME/PASSWORD) on a
 * PHP build with pdo_pgsql, pcntl and posix. Skipped otherwise.
 */
function forkRaceCounter(): void
{
    Schema::dropIfExists('race_counter');
    Schema::create('race_counter', function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('value');
    });
    DB::table('race_counter')->insert(['id' => 1, 'value' => 0]);
}

function forkRaceBump(bool $lock): int
{
    $query = DB::table('race_counter')->where('id', 1);
    $read = (int) ($lock ? $query->lockForUpdate() : $query)->value('value');

    // Widen the read-modify-write window so the two children genuinely overlap.
    usleep(200_000);

    DB::table('race_counter')->where('id', 1)->update(['value' => $read + 1]);

    return $read;
}

afterEach(function (): void {
    Schema::dropIfExists('race_counter');
});

it('сериализует read-modify-write под FOR UPDATE: оба ok, итог 2', function (): void {
    ForkRace::skipUnlessSupported($this);
    forkRaceCounter();

    $outcomes = ForkRace::run([
        fn () => DB::transaction(fn () => forkRaceBump(lock: true)),
        fn () => DB::transaction(fn () => forkRaceBump(lock: true)),
    ]);

    expect(array_map(fn (RaceOutcome $o) => $o->ok, $outcomes))->toBe([true, true])
        ->and(DB::table('race_counter')->value('value'))->toBe(2);
});

it('негативный контроль: без FOR UPDATE обновление теряется, итог 1', function (): void {
    ForkRace::skipUnlessSupported($this);
    forkRaceCounter();

    $outcomes = ForkRace::run([
        fn () => DB::transaction(fn () => forkRaceBump(lock: false)),
        fn () => DB::transaction(fn () => forkRaceBump(lock: false)),
    ]);

    expect(array_map(fn (RaceOutcome $o) => $o->ok, $outcomes))->toBe([true, true])
        ->and(array_map(fn (RaceOutcome $o) => $o->value, $outcomes))->toBe([0, 0])
        ->and(DB::table('race_counter')->value('value'))->toBe(1);
});

it('каждая задача имеет своё соединение и ждёт готовности всех участников', function (): void {
    ForkRace::skipUnlessSupported($this);

    $parentPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;

    $outcomes = ForkRace::run([
        fn () => (int) DB::selectOne('select pg_backend_pid() as pid')->pid,
        fn () => (int) DB::selectOne('select pg_backend_pid() as pid')->pid,
    ]);

    [$first, $second] = array_map(fn (RaceOutcome $o) => $o->value, $outcomes);

    expect($outcomes[0]->ok && $outcomes[1]->ok)->toBeTrue()
        ->and($first)->toBeInt()->not->toBe($second)
        ->and($first)->not->toBe($parentPid)
        ->and($second)->not->toBe($parentPid);

    $dir = sys_get_temp_dir().'/vlt_readiness_'.bin2hex(random_bytes(8));
    mkdir($dir);
    $connection = DB::getDefaultConnection();
    $factory = app('db.factory');
    $parentProcess = getmypid();

    // Exactly one child fails before announcing ready. Its sibling must time out
    // at the barrier without running a task, regardless of fork scheduling.
    DB::extend($connection, static function (array $config, string $name) use ($factory, $parentProcess, $dir): Connection {
        if (getmypid() !== $parentProcess) {
            $claim = @fopen($dir.'/failed-startup', 'x');

            if ($claim !== false) {
                fclose($claim);

                throw new RuntimeException('startup failed before ready');
            }
        }

        return $factory->make($config, $name);
    });

    try {
        $task = static function () use ($dir): int {
            file_put_contents($dir.'/task-started', '1');

            return (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
        };
        $outcomes = ForkRace::run([$task, $task], timeoutMs: 200);

        expect(array_map(fn (RaceOutcome $o) => $o->exception, $outcomes))
            ->toEqualCanonicalizing([RuntimeException::class, 'ForkRace\\BarrierTimeout'])
            ->and(file_exists($dir.'/task-started'))->toBeFalse();
    } finally {
        DB::forgetExtension($connection);
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }
});

it('исключение задачи возвращается как исход, соседняя задача ok', function (): void {
    ForkRace::skipUnlessSupported($this);

    $outcomes = ForkRace::run([
        fn () => throw new RuntimeException('boom'),
        fn () => 'fine',
    ]);

    expect($outcomes[0]->ok)->toBeFalse()
        ->and($outcomes[0]->failedWith(RuntimeException::class))->toBeTrue()
        ->and($outcomes[0]->message)->toBe('boom')
        ->and($outcomes[1]->ok)->toBeTrue()
        ->and($outcomes[1]->value)->toBe('fine');
});
