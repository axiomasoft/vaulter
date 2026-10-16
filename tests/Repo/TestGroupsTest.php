<?php

declare(strict_types=1);

use Vaulter\Testing\Support\TestGroups;

/*
 * The test groups of PLAN3 P1.5 (database, serial, extended, stand, engine:*): every suite declares them in <tests>/groups.php,
 * TestGroups turns them into Pest groups, tests/zones.json repeats the database list for `scripts/t --changed --db`, and
 * scripts/t selects by them. Everything here is read-only: files, `pest --list-tests` and `scripts/t --dry-run`.
 */

/** @return array<string, string> suite → its tests directory (repo-relative) that owns a groups.php */
function g_suites(): array
{
    return [
        'core' => 'packages/core/tests',
        'media' => 'packages/media/tests',
        'drive' => 'packages/drive/tests',
        'documents' => 'packages/documents/tests',
        'azgard' => 'packages/azgard/tests',
        'filament' => 'packages/filament/tests',
        'load' => 'load/tests',
    ];
}

function g_root(): string
{
    return dirname(__DIR__, 2);
}

/** @return array<string, list<string>> */
function g_declared(string $suite): array
{
    return TestGroups::declared(g_root().'/'.g_suites()[$suite]);
}

function g_under(string $path, string $entry): bool
{
    return $path === $entry || str_starts_with($path, $entry.'/');
}

/** @return list<string> every test file (repo-relative) of a suite directory that a groups.php entry covers */
function g_files(string $suite, string $entry): array
{
    $absolute = g_root().'/'.g_suites()[$suite].'/'.$entry;
    if (is_file($absolute)) {
        return [g_suites()[$suite].'/'.$entry];
    }
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), 'Test.php')) {
            $files[] = substr($file->getPathname(), strlen(g_root()) + 1);
        }
    }
    sort($files);

    return $files;
}

/**
 * @param  list<string> $paths
 * @return list<string> test names `pest --list-tests --group=<group>` prints for a suite directory
 */
function g_listed(string $cwd, string $group, array $paths = []): array
{
    $process = proc_open(
        [g_root().'/scripts/php84', 'php', g_root().'/vendor/bin/pest', '--list-tests', '--group='.$group, ...$paths],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        array_filter(getenv(), static fn (string $key): bool => preg_match('/^(DB_|TEST_|TESTBENCH_|APP_BASE_PATH)/', $key) !== 1, ARRAY_FILTER_USE_KEY),
    );
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $code = proc_close($process);
    expect($code)->toBeIn([0, 1], $err);

    preg_match_all('/^ - (.+)$/m', $out, $matches);
    sort($matches[1]);

    return $matches[1];
}

/**
 * @param  list<string>                                           $args
 * @return array{0: int, 1: array<string, mixed>|null, 2: string}
 */
function g_t(array $args): array
{
    $process = proc_open(
        ['bash', g_root().'/scripts/t', ...$args, '--dry-run', '--json'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        g_root(),
        array_filter(getenv(), static fn (string $key): bool => preg_match('/^(DB_|VAULTER_|TEST_|T_|TESTBENCH_|APP_BASE_PATH|PEST_FLAGS)/', $key) !== 1, ARRAY_FILTER_USE_KEY) + ['T_CPUS' => '8'],
    );
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $code = proc_close($process);

    return [$code, $code === 0 ? json_decode($out, true, flags: JSON_THROW_ON_ERROR) : null, $err];
}

it('declares only known groups with paths that exist, each once, in every suite', function (): void {
    foreach (g_suites() as $suite => $dir) {
        expect(is_file(g_root().'/'.$dir.'/groups.php'))->toBeTrue("$suite has no groups.php");

        foreach (g_declared($suite) as $group => $paths) {
            expect($paths)->toBe(array_values(array_unique($paths)), "$suite lists a path twice in [$group]");
            foreach ($paths as $path) {
                expect(file_exists(g_root().'/'.$dir.'/'.$path))->toBeTrue("$suite [$group]: $path does not exist");
            }
        }
    }
});

it('derives nofast and nofull from the group instead of repeating them by hand', function (): void {
    expect(TestGroups::expand('serial'))->toBe(['serial', 'nofast'])
        ->and(TestGroups::expand('extended'))->toBe(['extended', 'nofast'])
        ->and(TestGroups::expand('stand'))->toBe(['stand', 'nofast', 'nofull'])
        ->and(TestGroups::expand('database'))->toBe(['database'])
        ->and(TestGroups::expand('database', 'media'))->toBe(['database', 'database:media'])
        ->and(TestGroups::expand('engine:server'))->toBe(['engine:server']);
});

it('keeps an engine-only or serial test inside the database layer', function (): void {
    foreach (g_suites() as $suite => $dir) {
        $declared = g_declared($suite);
        $database = array_merge(...array_map(static fn (string $entry): array => g_files($suite, $entry), $declared['database'] ?? []));

        foreach (array_filter(array_keys($declared), static fn (string $group): bool => $group === 'serial' || str_starts_with($group, 'engine:')) as $group) {
            foreach ($declared[$group] as $entry) {
                foreach (g_files($suite, $entry) as $file) {
                    expect(in_array($file, $database, true))->toBeTrue("$suite: $file is [$group] but not [database]: a race or an engine-only test is an engine test");
                }
            }
        }
    }
});

it('puts the load suite in stand and nothing else, with no scale test moved without a cheap equivalent', function (): void {
    $load = g_declared('load');
    expect($load['stand'])->not->toBe([])->and($load['database'])->toBe([])->and($load['serial'])->toBe([]);

    foreach (array_diff(array_keys(g_suites()), ['load']) as $suite) {
        expect(g_declared($suite)['stand'] ?? [])->toBe([], "$suite must not hold stand tests: external services belong to the load/stand suite");
    }

    // `extended` stays empty until a scale test has a cheap same-defect equivalent on the PR lane (classification.md, Extended).
    foreach (array_keys(g_suites()) as $suite) {
        expect(g_declared($suite)['extended'] ?? [])->toBe([], "$suite: a test enters extended only together with its documented cheap equivalent");
    }
});

it('keeps the database list of tests/zones.json equal to the union of the suite declarations', function (): void {
    $map = json_decode((string) file_get_contents(g_root().'/tests/zones.json'), true, flags: JSON_THROW_ON_ERROR);

    $declared = [];
    foreach (g_suites() as $suite => $dir) {
        foreach (g_declared($suite)['database'] ?? [] as $path) {
            $declared[] = $dir.'/'.$path;
        }
    }
    $listed = $map['database'];
    sort($declared);
    sort($listed);

    expect($listed)->toBe($declared);
});

it('lets every core database test name the zone that owns it, so database:<zone> exists', function (): void {
    $map = json_decode((string) file_get_contents(g_root().'/tests/zones.json'), true, flags: JSON_THROW_ON_ERROR);
    $leaves = array_filter($map['zones'], static fn (array $zone): bool => ($zone['parent'] ?? null) === 'core');

    foreach (g_declared('core')['database'] as $entry) {
        $path = 'packages/core/tests/'.$entry;
        $owners = array_keys(array_filter($leaves, static fn (array $zone): bool => array_filter($zone['tests'], static fn (string $test): bool => g_under($path, $test)) !== []));
        expect($owners)->toHaveCount(1, "$path is claimed by ".json_encode($owners));
    }
});

it('gives every serial and stand test the umbrella groups the lanes exclude (nofast, nofull)', function (): void {
    $core = ['serial' => g_listed(g_root(), 'serial'), 'nofast' => g_listed(g_root(), 'nofast')];
    expect($core['serial'])->not->toBe([])->and(array_diff($core['serial'], $core['nofast']))->toBe([]);

    $drive = ['serial' => g_listed(g_root().'/packages/drive', 'serial'), 'nofast' => g_listed(g_root().'/packages/drive', 'nofast')];
    expect($drive['serial'])->not->toBe([])->and(array_diff($drive['serial'], $drive['nofast']))->toBe([]);

    // the load suite has its own path line: the default root suite does not include it
    $stand = g_listed(g_root(), 'stand', ['load/tests']);
    expect($stand)->not->toBe([])
        ->and(array_diff($stand, g_listed(g_root(), 'nofast', ['load/tests'])))->toBe([])
        ->and(array_diff($stand, g_listed(g_root(), 'nofull', ['load/tests'])))->toBe([]);
});

it('plans stand as its own command: load tests and real-process scripts, SQLite, one process, never k6 or a benchmark', function (): void {
    [$code, $plan, $err] = g_t(['stand']);
    expect($code)->toBe(0, $err)->and($plan['mode'])->toBe('stand')->and($plan['engines'])->toBe(['sqlite'])
        ->and($plan['suites'])->toBe(['load', 'collab-e2e']);

    $pest = array_values(array_filter($plan['steps'], static fn (array $step): bool => $step['kind'] === 'pest'));
    expect($pest)->not->toBe([]);
    foreach ($pest as $step) {
        expect($step['group'])->toBe('stand')->and($step['processes'])->toBe(1)->and($step['exclude'])->toBe([])->and($step['engine'])->toBe('sqlite');
    }
    expect(json_encode($plan))->not->toMatch('/k6|benchmark|bench\b/i');

    foreach ([['sqlite'], ['sqlite', '--full'], ['all', '--full']] as $args) {
        [$other, $otherPlan] = g_t($args);
        expect($other)->toBe(0)->and($otherPlan['suites'])->not->toContain('load')->and($otherPlan['suites'])->not->toContain('collab-e2e');
    }
});

it('refuses engines, --full and --changed for stand, and names the reason', function (): void {
    foreach ([['stand', 'pgsql'], ['stand', '--full'], ['stand', '--engines=mysql'], ['stand', '--changed']] as $args) {
        [$code, , $err] = g_t($args);
        expect($code)->toBe(2, implode(' ', $args))->and($err)->toContain('`stand` runs on SQLite');
    }
});

it('drops the exclusion a selected group implies, so extended and stand never select nothing', function (): void {
    [, $extended] = g_t(['sqlite', '-p', 'media', '--group=extended']);
    foreach ($extended['steps'] as $step) {
        expect($step['exclude'])->not->toContain('nofast');
    }

    [, $stand] = g_t(['sqlite', '-p', 'load', '--group=stand']);
    foreach ($stand['steps'] as $step) {
        expect($step['exclude'])->not->toContain('nofast')->and($step['exclude'])->not->toContain('nofull');
    }

    [, $standFull] = g_t(['sqlite', '-p', 'load', '--full', '--group=stand']);
    foreach ($standFull['steps'] as $step) {
        expect($step['exclude'])->not->toContain('nofull')->and($step['exclude'])->toContain('serial');
    }

    // an ordinary lane keeps its exclusions
    [, $fast] = g_t(['sqlite', '-p', 'media']);
    foreach ($fast['steps'] as $step) {
        expect($step['exclude'])->toBe(['nofast']);
    }
    [, $full] = g_t(['sqlite', '-p', 'media', '--full']);
    expect($full['steps'][0]['exclude'])->toBe(['nofull', 'serial']);
});

it('runs the database layer of a package on a server engine without its database-free files', function (): void {
    [$code, $plan, $err] = g_t(['pgsql', '-p', 'drive', '--group=database']);
    expect($code)->toBe(0, $err);

    $files = array_merge(...array_column($plan['steps'], 'files'));
    expect($files)->toContain('packages/drive/tests/Feature/QuotaReserveTest.php')
        ->and($files)->not->toContain('packages/drive/tests/Arch/ArchTest.php');
    foreach ($plan['steps'] as $step) {
        expect($step['group'])->toBe('database')->and($step['exclude'])->toBe(['nofast']);
    }
    expect(array_column($plan['skipped'], 'partition'))->toContain('TestCase+WithoutDatabaseSchema');
});
