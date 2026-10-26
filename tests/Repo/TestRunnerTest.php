<?php

declare(strict_types=1);

/*
 * scripts/t, driven without Laravel, a database or a real Pest: either read-only against the real inventory
 * (`--dry-run --json`), or against a throw-away fixture repo (T_ROOT) whose `vendor/bin/pest` is a fake that records
 * how it was called.
 */

function t_real_root(): string
{
    return dirname(__DIR__, 2);
}

function t_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function t_write(string $path, string $contents, bool $executable = false): void
{
    is_dir(dirname($path)) || mkdir(dirname($path), 0o777, true);
    file_put_contents($path, $contents);
    $executable && chmod($path, 0o755);
}

/** The suite's own phpunit.xml exports DB_* and friends; a child runner must not inherit them. */
function t_clean_env(): array
{
    return array_filter(
        getenv(),
        static fn (string $key): bool => preg_match('/^(DB_|VAULTER_|TEST_|T_|TESTBENCH_|APP_BASE_PATH|PEST_FLAGS)/', $key) !== 1,
        ARRAY_FILTER_USE_KEY,
    );
}

/**
 * @param  array<int, string>                  $args
 * @param  array<string, string>               $env
 * @return array{0: int, 1: string, 2: string}
 */
function t_run(array $args, array $env = [], ?string $root = null, ?string $cwd = null): array
{
    $process = proc_open(
        ['bash', t_real_root().'/scripts/t', ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd ?? t_real_root(),
        array_merge(t_clean_env(), ['T_CPUS' => '8'], $root === null ? [] : ['T_ROOT' => $root], $env),
    );
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $out, $err];
}

/** @return array<string, mixed> */
function t_plan(array $args, array $env = [], ?string $root = null): array
{
    $dashes = array_search('--', $args, true);
    $args = $dashes === false ? [...$args, '--dry-run', '--json'] : [...array_slice($args, 0, $dashes), '--dry-run', '--json', ...array_slice($args, $dashes)];
    [$code, $out, $err] = t_run($args, $env, $root);
    expect($code)->toBe(0, $err);

    return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
}

function t_fake_pest(): string
{
    return <<<'PHP'
        <?php
        $args = $argv;
        array_shift($args);
        if (in_array('--list-tests', $args, true)) {
            echo "\nAvailable tests:\n";
            foreach (is_file('fake-tests.txt') ? file('fake-tests.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $name) {
                echo " - $name\n";
            }
            exit(0);
        }
        $config = '';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--configuration=')) {
                $config = substr($arg, 16);
            }
        }
        preg_match_all('#<file>([^<]+)</file>#', (string) file_get_contents($config), $m);
        file_put_contents((string) getenv('T_LOG'), json_encode([
            'cwd' => getcwd(),
            'args' => $args,
            'files' => $m[1],
            'excluded' => (function () use ($config): array {
                preg_match('#<exclude>(.*?)</exclude>#s', (string) file_get_contents($config), $e);
                preg_match_all('#<group>([^<]+)</group>#', $e[1] ?? '', $g);

                return $g[1];
            })(),
            'config' => $config,
            'env' => [
                'DB_CONNECTION' => getenv('DB_CONNECTION'),
                'DB_DATABASE' => getenv('DB_DATABASE'),
                'PER_PROCESS' => getenv('VAULTER_TEST_DB_PER_PROCESS'),
                'SKELETON' => getenv('TESTBENCH_APP_BASE_PATH'),
                'FULL_SEEDS' => getenv('VAULTER_FULL_SEEDS'),
                'SEED_SUBSET' => getenv('VAULTER_SEED_SUBSET'),
            ],
        ])."\n", FILE_APPEND);
        $serial = in_array('--group=serial', $args, true);
        if ($serial && getenv('T_FAKE_NOTESTS_SERIAL')) {
            echo "\n  INFO  No tests found.\n";
            exit(1);
        }
        if (getenv('T_FAKE_FAIL')) {
            echo "\n  Tests:    1 failed, 2 passed\n";
            exit(1);
        }
        if (getenv('T_FAKE_SKIP')) {
            echo "\n  Tests:    2 skipped, 3 passed\n";
            exit(0);
        }
        echo "\n  Tests:    3 passed\n";
        PHP;
}

/** @return array{root: string, log: string} */
function t_fixture(): array
{
    $root = sys_get_temp_dir().'/vaulter-t-fixture-'.bin2hex(random_bytes(4));
    mkdir($root, 0o777, true);
    register_shutdown_function(static fn () => t_rmdir($root));

    $partition = static fn (string $key, array $files, array $extra = []): array => $extra + [
        'key' => $key, 'fingerprint' => 'x', 'lifecycle' => 'refresh', 'database' => true,
        'engines' => ['sqlite', 'pgsql', 'mysql'], 'skeleton_writer' => false, 'empty_passes' => ['serial'], 'files' => $files,
    ];
    $inventory = ['version' => 1, 'suites' => [
        'alpha' => ['kind' => 'pest', 'cwd' => 'packages/alpha', 'vendor' => 'vendor/bin/pest', 'config' => 'phpunit.xml',
            'tests_dir' => 'packages/alpha/tests', 'path_base' => 'package', 'default' => true, 'discovery' => ['--list-tests'],
            'partitions' => [
                $partition('RefreshDatabase+TestCase', ['packages/alpha/tests/A1Test.php', 'packages/alpha/tests/A2Test.php', 'packages/alpha/tests/Unit/A3Test.php']),
                $partition('RefreshDatabase+TestCase#skeleton', ['packages/alpha/tests/Console/MakeXTest.php'], ['skeleton_writer' => true]),
                $partition('(unbound)', ['packages/alpha/tests/ArchTest.php'], ['lifecycle' => 'none', 'database' => false, 'engines' => ['sqlite']]),
            ]],
        'beta' => ['kind' => 'pest', 'cwd' => '.', 'vendor' => 'vendor/bin/pest', 'config' => 'phpunit.xml',
            'tests_dir' => 'tests/beta', 'path_base' => 'repo', 'default' => true, 'discovery' => ['--list-tests'],
            'partitions' => [$partition('RefreshDatabase+TestCase', ['tests/beta/B1Test.php', 'tests/beta/B2Test.php'], ['empty_passes' => []])]],
        'noded' => ['kind' => 'node', 'cwd' => '.', 'default' => true, 'command' => ['bash', 'fake-node.sh']],
    ]];
    t_write($root.'/tests/suites.json', json_encode($inventory, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $xml = '<?xml version="1.0"?>'."\n".'<phpunit bootstrap="vendor/autoload.php">'."\n".'    <testsuites>'."\n".'        <testsuite name="x"><directory>tests</directory></testsuite>'."\n".'    </testsuites>'."\n".'</phpunit>'."\n";
    t_write($root.'/phpunit.xml', $xml);
    t_write($root.'/packages/alpha/phpunit.xml', $xml);
    t_write($root.'/vendor/bin/pest', t_fake_pest());
    t_write($root.'/packages/alpha/vendor/bin/pest', t_fake_pest());
    t_write($root.'/scripts/php84', "#!/usr/bin/env bash\nexec \"\$@\"\n", true);
    t_write($root.'/fake-node.sh', "#!/usr/bin/env bash\necho \"node \$* in \$(pwd)\" >> \"\$T_LOG.node\"\n", true);
    foreach (['test-db.sh', 'db-test-env.sh', 'db-reap.sh'] as $script) {
        t_write($root.'/scripts/'.$script, (string) file_get_contents(t_real_root().'/scripts/'.$script), true);
    }
    t_write($root.'/skeleton-src/config/app.php', "<?php return [];\n");
    t_write($root.'/bin/psql', "#!/usr/bin/env bash\necho \"\$*\" >> \"\$T_LOG.psql\"\n", true);

    return ['root' => $root, 'log' => $root.'/calls.log'];
}

/** @return array<int, array<string, mixed>> */
function t_calls(string $log): array
{
    return is_file($log)
        ? array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_filter(explode("\n", (string) file_get_contents($log))))
        : [];
}

function t_env(array $fx, array $extra = []): array
{
    return $extra + ['T_LOG' => $fx['log'], 'T_TMP' => $fx['root'].'/var/t', 'T_SKELETON_SRC' => $fx['root'].'/skeleton-src', 'PATH' => $fx['root'].'/bin:'.getenv('PATH')];
}

it('prints help and an empty-argument dry run without touching anything', function (): void {
    [$code, $out] = t_run(['--help']);
    expect($code)->toBe(0)->and($out)->toContain('scripts/t [sqlite|pgsql|mysql|all]');
});

it('plans exactly the inventory partitions of every PHP suite, and the inventory covers every test file once', function (): void {
    $inventory = json_decode((string) file_get_contents(t_real_root().'/tests/suites.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($inventory['suites'] as $name => $suite) {
        if ($suite['kind'] !== 'pest') {
            continue;
        }
        $inventoried = array_merge(...array_column($suite['partitions'], 'files'));
        expect(count($inventoried))->toBe(count(array_unique($inventoried)), "$name lists a file twice");

        $onDisk = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(t_real_root().'/'.$suite['tests_dir'], FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), 'Test.php')) {
                $onDisk[] = substr($file->getPathname(), strlen(t_real_root()) + 1);
            }
        }
        sort($onDisk);
        sort($inventoried);
        expect($inventoried)->toBe($onDisk, "$name: the inventory and the test files differ");

        $plan = t_plan(['sqlite', '-p', (string) $name, '-j1']);
        $planned = array_merge(...array_column($plan['steps'], 'files'));
        sort($planned);
        $applicable = array_values(array_filter($suite['partitions'], static fn (array $partition): bool => in_array('sqlite', $partition['engines'], true)));
        $eligible = array_merge(...array_column($applicable, 'files'));
        sort($eligible);
        expect($planned)->toBe($eligible, "$name: the plan does not cover its SQLite-applicable inventory");
        expect(array_values(array_unique(array_column($plan['steps'], 'partition'))))->toBe(array_column($applicable, 'key'));
        foreach ($suite['partitions'] as $partition) {
            if (!in_array('sqlite', $partition['engines'], true)) {
                $skipped = array_values(array_filter($plan['skipped'], static fn (array $skip): bool => $skip['partition'] === $partition['key']));
                expect($skipped)->toHaveCount(1)
                    ->and($skipped[0]['reason'])->toBe('partition not applicable to sqlite');
            }
        }
    }
});

it('selects the repo suite and keeps the default suites free of load', function (): void {
    $plan = t_plan(['sqlite', '-p', 'repo']);
    $inventory = json_decode((string) file_get_contents(t_real_root().'/tests/suites.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($plan['suites'])->toBe(['repo'])->and($plan['steps'][0]['files'])->toBe($inventory['suites']['repo']['partitions'][0]['files']);

    $default = t_plan(['sqlite']);
    expect($default['suites'])->toBe(['core', 'integration', 'media', 'drive', 'webhooks', 'documents', 'collaboration', 'azgard', 'filament', 'repo', 'hub', 'vue', 'vue-collaboration']);
});

it('runs a package path from the package cwd with a generated config holding only the selected files', function (): void {
    $fx = t_fixture();
    [$code] = t_run(['sqlite', '-p', 'alpha', '--', 'tests/A1Test.php'], t_env($fx), $fx['root']);

    $calls = t_calls($fx['log']);
    expect($code)->toBe(0)->and($calls)->toHaveCount(1)
        ->and($calls[0]['cwd'])->toBe(realpath($fx['root'].'/packages/alpha'))
        ->and($calls[0]['files'])->toBe([$fx['root'].'/packages/alpha/tests/A1Test.php'])
        ->and($calls[0]['env']['DB_CONNECTION'])->toBe('sqlite');
});

it('infers the suite from a repo-relative path and refuses several suites for one path list', function (): void {
    $fx = t_fixture();
    [$code] = t_run(['sqlite', 'tests/beta/B1Test.php'], t_env($fx), $fx['root']);
    expect($code)->toBe(0)->and(t_calls($fx['log'])[0]['cwd'])->toBe(realpath($fx['root']));

    [$code, , $err] = t_run(['sqlite', '-p', 'alpha,beta', '--', 'tests/A1Test.php'], t_env($fx), $fx['root']);
    expect($code)->toBe(2)->and($err)->toContain('several suites');
});

it('refuses an unknown path together with a known one and runs nothing', function (): void {
    $fx = t_fixture();
    [$code, , $err] = t_run(['sqlite', '-p', 'alpha', '--', 'tests/A1Test.php', 'tests/Nope/GhostTest.php'], t_env($fx), $fx['root']);

    expect($code)->toBe(2)->and($err)->toContain('GhostTest.php')->and(t_calls($fx['log']))->toBe([]);
});

it('runs one pass per partition, serially by schema, and the skeleton writer alone with a private skeleton', function (): void {
    $fx = t_fixture();
    [$code] = t_run(['sqlite', '-p', 'alpha', '-j4'], t_env($fx), $fx['root']);
    $calls = t_calls($fx['log']);

    expect($code)->toBe(0)->and($calls)->toHaveCount(3);
    expect(array_map(static fn (array $c): array => array_map(static fn (string $f): string => basename($f), $c['files']), $calls))->toBe([
        ['A1Test.php', 'A2Test.php', 'A3Test.php'], ['MakeXTest.php'], ['ArchTest.php'],
    ]);
    expect($calls[0]['args'])->toContain('--parallel', '--processes=3')->and($calls[1]['args'])->not->toContain('--parallel');
    expect($calls[1]['env']['SKELETON'])->not->toBe('')->and($calls[1]['env']['SKELETON'])->not->toBe(false)
        ->and($calls[0]['env']['SKELETON'])->toBeFalse();
});

it('gives every skeleton-writing step a skeleton path of its own', function (): void {
    $fx = t_fixture();
    t_run(['sqlite', '-p', 'alpha', '-j1', '--full', '--', 'tests/Console'], t_env($fx), $fx['root']);
    t_run(['sqlite', '-p', 'alpha', '-j1', '--full', '--', 'tests/Console'], t_env($fx), $fx['root']);
    $paths = array_column(array_column(t_calls($fx['log']), 'env'), 'SKELETON');

    expect($paths)->toHaveCount(2)->and($paths[0])->not->toBe($paths[1])
        ->and($paths[0])->not->toBe($fx['root'].'/skeleton-src');
});

it('excludes nofast by default, nofull+serial then one serial pass for --full, and merges user exclusions into one', function (): void {
    $fx = t_fixture();
    $fast = t_plan(['sqlite', '-p', 'beta', '-j4'], root: $fx['root']);
    expect($fast['steps'])->toHaveCount(1)->and($fast['steps'][0]['exclude'])->toBe(['nofast']);

    $full = t_plan(['sqlite', '-p', 'beta', '-j4', '--full'], root: $fx['root']);
    expect(array_column($full['steps'], 'pass'))->toBe(['parallel', 'serial'])
        ->and($full['steps'][0]['exclude'])->toBe(['nofull', 'serial'])
        ->and($full['steps'][1]['group'])->toBe('serial');

    $merged = t_plan(['sqlite', '-p', 'beta', '-j4', '--exclude-group=slow', '--exclude-group=flaky'], root: $fx['root']);
    expect($merged['steps'][0]['exclude'])->toBe(['nofast', 'slow', 'flaky']);

    t_run(['sqlite', '-p', 'beta', '-j4', '--exclude-group=slow', '--exclude-group=flaky'], t_env($fx), $fx['root']);
    // the groups travel in the generated configuration: in --parallel mode ParaTest forwards at most one --exclude-group flag
    $call = t_calls($fx['log'])[0];
    $flags = array_values(array_filter($call['args'], static fn (string $a): bool => str_starts_with($a, '--exclude-group')));
    expect($flags)->toBe([])->and($call['excluded'])->toBe(['nofast', 'slow', 'flaky']);
});

it('runs serial exactly once: --group=serial is one pass, -j1 never adds a second', function (): void {
    $fx = t_fixture();
    $serial = t_plan(['sqlite', '-p', 'beta', '-j4', '--group=serial'], root: $fx['root']);
    expect($serial['steps'])->toHaveCount(1)->and($serial['steps'][0]['pass'])->toBe('serial')->and($serial['steps'][0]['exclude'])->toBe([]);

    $single = t_plan(['sqlite', '-p', 'beta', '-j1', '--full'], root: $fx['root']);
    expect(array_column($single['steps'], 'pass'))->toBe(['single'])->and($single['steps'][0]['exclude'])->toBe(['nofull']);

    [$code, , $err] = t_run(['sqlite', '-p', 'beta', '--group=serial', '--exclude-group=serial', '--dry-run'], [], $fx['root']);
    expect($code)->toBe(2)->and($err)->toContain('both selected and excluded');
});

it('forwards a group as the selector of every pass (intersection with the selected files, not a union)', function (): void {
    $fx = t_fixture();
    t_run(['sqlite', '-p', 'alpha', '-j2', '--group=database', '--', 'tests/A1Test.php', 'tests/A2Test.php'], t_env($fx), $fx['root']);
    $calls = t_calls($fx['log']);

    expect($calls)->toHaveCount(1)->and($calls[0]['args'])->toContain('--group=database')
        ->and($calls[0]['files'])->toBe([$fx['root'].'/packages/alpha/tests/A1Test.php', $fx['root'].'/packages/alpha/tests/A2Test.php']);
});

it('narrows --group=database to the classified files and leaves Node suites out of a group selection', function (): void {
    $fx = t_fixture();
    t_write($fx['root'].'/tests/zones.json', json_encode(['database' => ['packages/alpha/tests/A1Test.php']], JSON_THROW_ON_ERROR));
    $plan = t_plan(['pgsql', '--group=database'], root: $fx['root']);

    // beta has no classified file and the skeleton partition of alpha neither: no step, so no "zero tests" failure
    expect(array_map(static fn (array $s): string => $s['suite'].' '.$s['partition'], $plan['steps']))->toBe(['alpha RefreshDatabase+TestCase'])
        ->and($plan['steps'][0]['files'])->toBe(['packages/alpha/tests/A1Test.php'])
        ->and($plan['suites'])->not->toContain('noded');

    // other groups are not narrowed
    expect(t_plan(['pgsql', '-p', 'beta', '--group=serial'], root: $fx['root'])['steps'][0]['files'])->toHaveCount(2);
});

it('treats zero tests as an error except an empty serial pass the inventory allows', function (): void {
    $fx = t_fixture();
    $env = t_env($fx, ['T_FAKE_NOTESTS_SERIAL' => '1']);

    [$code, $out] = t_run(['sqlite', '-p', 'alpha', '-j4', '--full', '--', 'tests/A1Test.php', 'tests/A2Test.php'], $env, $fx['root']);
    expect($code)->toBe(0)->and($out)->toContain('empty (allowed by inventory)');

    [$code, $out] = t_run(['sqlite', '-p', 'beta', '-j4', '--full'], $env, $fx['root']);
    expect($code)->toBe(3)->and($out)->toContain('ZERO TESTS');
});

it('names an upstream failure and skipped tests honestly', function (): void {
    $fx = t_fixture();

    [$code, $out] = t_run(['sqlite', '-p', 'beta', '-j1'], t_env($fx, ['T_FAKE_FAIL' => '1']), $fx['root']);
    expect($code)->toBe(1)->and($out)->toContain('exit=1')->and($out)->toContain('FAIL');

    [$code, $out] = t_run(['sqlite', '-p', 'beta', '-j1'], t_env($fx, ['T_FAKE_SKIP' => '1']), $fx['root']);
    expect($code)->toBe(0)->and($out)->toContain('skipped=2');

    [$code, $out] = t_run(['sqlite', '-p', 'beta', '-j1', '--no-skips'], t_env($fx, ['T_FAKE_SKIP' => '1']), $fx['root']);
    expect($code)->toBe(1)->and($out)->toContain('skips refused');
});

it('runs Node suites once through their manifest command, whatever the engines', function (): void {
    $fx = t_fixture();
    t_run(['all', '-p', 'noded', '--', '--reporter=tap'], t_env($fx), $fx['root']);

    expect((string) file_get_contents($fx['log'].'.node'))->toBe('node --reporter=tap in '.realpath($fx['root'])."\n");
});

it('refuses a server engine without paths, group or --full, and an engine that disagrees with the environment', function (): void {
    $fx = t_fixture();
    [$code, , $err] = t_run(['pgsql', '-p', 'alpha'], t_env($fx), $fx['root']);
    expect($code)->toBe(2)->and($err)->toContain('no default server sweep');

    [$code, , $err] = t_run(['pgsql', '-p', 'beta', '--', 'tests/beta/B1Test.php'], t_env($fx, ['DB_EXTERNAL' => '1', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'fx_test']), $fx['root']);
    expect($code)->not->toBe(0)->and($err)->toContain('does not match requested engine')->and(t_calls($fx['log']))->toBe([]);

    [$code, , $err] = t_run(['pgsql', '-p', 'beta', '--', 'tests/beta/B1Test.php'], t_env($fx, ['DB_EXTERNAL' => '1', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'production']), $fx['root']);
    expect($code)->not->toBe(0)->and($err)->toContain('_test')->and(t_calls($fx['log']))->toBe([]);
});

it('skips database-free partitions on a server engine and names the skip', function (): void {
    $fx = t_fixture();
    $plan = t_plan(['pgsql', '-p', 'alpha', '--', 'tests/ArchTest.php', 'tests/A1Test.php'], root: $fx['root']);

    expect(array_column($plan['steps'], 'partition'))->toBe(['RefreshDatabase+TestCase'])
        ->and($plan['skipped'])->toHaveCount(1)
        ->and($plan['skipped'][0]['partition'])->toBe('(unbound)')
        ->and($plan['skipped'][0]['reason'])->toContain('database-free');

    [$code, , $err] = t_run(['pgsql', '-p', 'alpha', '--dry-run', '--', 'tests/ArchTest.php'], [], $fx['root']);
    expect($code)->toBe(2)->and($err)->toContain('the plan is empty');
});

it('provisions per-worker databases explicitly for an external server and flags per-process databases', function (): void {
    $fx = t_fixture();
    $env = t_env($fx, ['DB_EXTERNAL' => '1', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'fx_test', 'DB_PORT' => '5432', 'T_PARTITION_JOBS' => '1']);
    [$code, , $err] = t_run(['pgsql', '-p', 'alpha', '-j3', '--', 'tests/A1Test.php', 'tests/A2Test.php', 'tests/Unit/A3Test.php', 'tests/ArchTest.php'], $env, $fx['root']);

    $calls = t_calls($fx['log']);
    expect($code)->toBe(0, $err)->and($calls)->toHaveCount(1)
        ->and($calls[0]['env']['PER_PROCESS'])->toBe('1')
        ->and($calls[0]['env']['DB_DATABASE'])->toBe('fx_test')
        ->and($calls[0]['env']['DB_CONNECTION'])->toBe('pgsql');
    $created = (string) file_get_contents($fx['log'].'.psql');
    foreach (['fx_w1_test', 'fx_w2_test', 'fx_w3_test'] as $name) {
        expect($created)->toContain('CREATE DATABASE "'.$name.'"');
    }
    expect($created)->not->toContain('fx_w4_test');
});

it('gives every concurrently running partition databases of its own, named after the base database', function (): void {
    $fx = t_fixture();
    $env = t_env($fx, ['DB_EXTERNAL' => '1', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'fx_test', 'DB_PORT' => '5432']);
    [$code, , $err] = t_run(['pgsql', '-p', 'alpha', '-j3', '--', 'tests/A1Test.php', 'tests/A2Test.php', 'tests/Unit/A3Test.php', 'tests/ArchTest.php'], $env, $fx['root']);

    $calls = t_calls($fx['log']);
    expect($code)->toBe(0, $err)->and($calls)->toHaveCount(1)
        ->and($calls[0]['env']['DB_DATABASE'])->toBe('fx_p1_test');
    $created = (string) file_get_contents($fx['log'].'.psql');
    foreach (['fx_p1_test', 'fx_p1_w1_test', 'fx_p1_w2_test', 'fx_p1_w3_test'] as $name) {
        expect($created)->toContain('CREATE DATABASE "'.$name.'"');
    }
});

it('clears partial-seed variables for --full and discovery, and refuses a contradicting full-seed flag', function (): void {
    $fx = t_fixture();
    t_run(['sqlite', '-p', 'beta', '-j1', '--full'], t_env($fx, ['VAULTER_SEED_SUBSET' => '5']), $fx['root']);
    $env = t_calls($fx['log'])[0]['env'];
    expect($env['FULL_SEEDS'])->toBe('1')->and($env['SEED_SUBSET'])->toBeFalse();

    [$code, , $err] = t_run(['sqlite', '-p', 'beta', '--full'], t_env($fx, ['VAULTER_FULL_SEEDS' => '0']), $fx['root']);
    expect($code)->toBe(2)->and($err)->toContain('VAULTER_FULL_SEEDS');
});

/**
 * @param  array<string, array<int, string>> $lists suite => names
 * @return array{root: string, log: string}
 */
function t_baseline_fixture(array $original, array $current): array
{
    $fx = t_fixture();
    t_write($fx['root'].'/packages/alpha/fake-tests.txt', implode("\n", $current['alpha'])."\n");
    t_write($fx['root'].'/fake-tests.txt', implode("\n", $current['beta'])."\n");
    foreach ($original as $suite => $names) {
        t_write($fx['root']."/tests/baseline/original/$suite.list", $names === [] ? '' : implode("\n", $names)."\n");
    }
    t_write($fx['root'].'/tests/baseline/CHANGES.json', json_encode(['schema' => 1, 'entries' => [], 'additions' => new stdClass]));

    return $fx;
}

function t_ledger(array $fx, array $entries): void
{
    t_write($fx['root'].'/tests/baseline/CHANGES.json', json_encode(['schema' => 1, 'entries' => $entries, 'additions' => new stdClass]));
}

it('compares discovery with the immutable original: equal passes, a deleted test fails, a ledgered replacement passes', function (): void {
    $names = ['P\\A::one', 'P\\A::two'];
    $fx = t_baseline_fixture(['alpha' => $names, 'beta' => ['P\\B::one']], ['alpha' => $names, 'beta' => ['P\\B::one']]);
    [$code, $out] = t_run(['baseline', '--compare', '-p', 'alpha,beta'], t_env($fx), $fx['root']);
    expect($code)->toBe(0)->and($out)->toContain('ok   alpha');

    // the test is deleted: count alone is not enough, the ledger decides
    t_write($fx['root'].'/packages/alpha/fake-tests.txt', "P\\A::one\nP\\A::three\n");
    [$code, $out] = t_run(['baseline', '--compare', '-p', 'alpha'], t_env($fx), $fx['root']);
    expect($code)->toBe(1)->and($out)->toContain('missing without ledger entry: P\\A::two');

    // arbitrary prose does not hide it: no decision, replacement absent from the current list
    t_ledger($fx, [['suite' => 'alpha', 'old' => 'P\\A::two', 'new' => 'P\\A::gone', 'reason' => 'merged', 'decision' => 'D4']]);
    [$code, $out] = t_run(['baseline', '--compare', '-p', 'alpha'], t_env($fx), $fx['root']);
    expect($code)->toBe(1)->and($out)->toContain('replacement not in the current list');

    t_ledger($fx, [['suite' => 'alpha', 'old' => 'P\\A::two', 'new' => 'P\\A::three', 'reason' => 'renamed', 'decision' => '']]);
    [$code, $out] = t_run(['baseline', '--compare', '-p', 'alpha'], t_env($fx), $fx['root']);
    expect($code)->toBe(1)->and($out)->toContain('lacks decision/reason');

    t_ledger($fx, [['suite' => 'alpha', 'old' => 'P\\A::two', 'new' => 'P\\A::three', 'reason' => 'renamed', 'decision' => 'D4']]);
    [$code, $out] = t_run(['baseline', '--compare', '-p', 'alpha'], t_env($fx), $fx['root']);
    expect($code)->toBe(0)->and($out)->toContain('added 1');
});

it('rejects a stale ledger entry and one that names a test the original never had', function (): void {
    $fx = t_baseline_fixture(['alpha' => ['P\\A::one']], ['alpha' => ['P\\A::one'], 'beta' => []]);

    t_ledger($fx, [['suite' => 'alpha', 'old' => 'P\\A::one', 'new' => 'P\\A::one', 'reason' => 'x', 'decision' => 'D4']]);
    [$code, $out] = t_run(['baseline', '--compare', '-p', 'alpha'], t_env($fx), $fx['root']);
    expect($code)->toBe(1)->and($out)->toContain('stale ledger entry');

    t_ledger($fx, [['suite' => 'alpha', 'old' => 'P\\A::ghost', 'new' => 'P\\A::one', 'reason' => 'x', 'decision' => 'D4']]);
    [$code, $out] = t_run(['baseline', '--compare', '-p', 'alpha'], t_env($fx), $fx['root']);
    expect($code)->toBe(1)->and($out)->toContain('not in the original');
});

it('accepts a test that moved to another suite: the ledger entry names new_suite and the replacement is looked up there', function (): void {
    $fx = t_baseline_fixture(['alpha' => ['P\\A::one', 'P\\A::two'], 'beta' => ['P\\B::one']], ['alpha' => ['P\\A::one'], 'beta' => ['P\\B::one', 'P\\B::two']]);

    // without new_suite the replacement is searched in the own suite only
    t_ledger($fx, [['suite' => 'alpha', 'old' => 'P\\A::two', 'new' => 'P\\B::two', 'reason' => 'moved', 'decision' => 'D40']]);
    [$code, $out] = t_run(['baseline', '--compare', '-p', 'alpha'], t_env($fx), $fx['root']);
    expect($code)->toBe(1)->and($out)->toContain('replacement not in the current list for: P\\A::two');

    // the other suite is discovered on demand, even when it is not among the compared ones
    t_ledger($fx, [['suite' => 'alpha', 'old' => 'P\\A::two', 'new' => 'P\\B::two', 'new_suite' => 'beta', 'reason' => 'moved', 'decision' => 'D40']]);
    [$code, $out] = t_run(['baseline', '--compare', '-p', 'alpha'], t_env($fx), $fx['root']);
    expect($code)->toBe(0)->and($out)->toContain('ok   alpha');

    // the replacement is absent from new_suite although it exists in the own suite
    t_write($fx['root'].'/fake-tests.txt', "P\\B::one\n");
    [$code, $out] = t_run(['baseline', '--compare', '-p', 'alpha,beta'], t_env($fx), $fx['root']);
    expect($code)->toBe(1)->and($out)->toContain('replacement not in the current list of [beta] for: P\\A::two');
});

it('rejects a cross-suite entry whose test is still in its old suite, and an unknown new_suite', function (): void {
    $fx = t_baseline_fixture(['alpha' => ['P\\A::one']], ['alpha' => ['P\\A::one'], 'beta' => ['P\\A::one']]);

    t_ledger($fx, [['suite' => 'alpha', 'old' => 'P\\A::one', 'new' => 'P\\A::one', 'new_suite' => 'beta', 'reason' => 'moved', 'decision' => 'D40']]);
    [$code, $out] = t_run(['baseline', '--compare', '-p', 'alpha'], t_env($fx), $fx['root']);
    expect($code)->toBe(1)->and($out)->toContain('stale ledger entry');

    t_write($fx['root'].'/packages/alpha/fake-tests.txt', "P\\A::two\n");
    t_ledger($fx, [['suite' => 'alpha', 'old' => 'P\\A::one', 'new' => 'P\\A::one', 'new_suite' => 'nowhere', 'reason' => 'moved', 'decision' => 'D40']]);
    [$code, $out] = t_run(['baseline', '--compare', '-p', 'alpha'], t_env($fx), $fx['root']);
    expect($code)->toBe(1)->and($out)->toContain('unknown new_suite [nowhere]')->and($out)->toContain('replacement not in the current list of [nowhere]');
});

it('does not count a test that moved in from another suite as an addition, and shows new_suite in the view', function (): void {
    $fx = t_baseline_fixture(['alpha' => ['P\\A::one', 'P\\A::two'], 'beta' => ['P\\B::one']], ['alpha' => ['P\\A::one'], 'beta' => ['P\\B::one', 'P\\B::two', 'P\\B::fresh']]);
    t_ledger($fx, [['suite' => 'alpha', 'old' => 'P\\A::two', 'new' => 'P\\B::two', 'new_suite' => 'beta', 'reason' => 'moved', 'decision' => 'D40']]);
    [$code] = t_run(['baseline', '--write', '-p', 'alpha,beta'], t_env($fx), $fx['root']);
    $ledger = json_decode((string) file_get_contents($fx['root'].'/tests/baseline/CHANGES.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and($ledger['additions']['beta']['names'])->toBe(['P\\B::fresh'])
        ->and($ledger['additions']['beta']['count'])->toBe(1)
        ->and($ledger['entries'][0]['new_suite'])->toBe('beta')
        ->and(file_get_contents($fx['root'].'/tests/baseline/CHANGES.md'))->toContain('[`beta`] `P\\B::two`');
});

it('writes current lists and ledger additions without ever rewriting an original', function (): void {
    $fx = t_baseline_fixture(['alpha' => ['P\\A::one']], ['alpha' => ['P\\A::one', 'P\\A::two'], 'beta' => ['P\\B::new']]);
    [$code] = t_run(['baseline', '--write', '-p', 'alpha,beta'], t_env($fx), $fx['root']);

    expect($code)->toBe(0)
        ->and(file_get_contents($fx['root'].'/tests/baseline/original/alpha.list'))->toBe("P\\A::one\n")
        ->and(file_get_contents($fx['root'].'/tests/baseline/alpha.count'))->toBe("2\n")
        ->and(file_get_contents($fx['root'].'/tests/baseline/original/beta.list'))->toBe('')
        ->and(file_get_contents($fx['root'].'/tests/baseline/original/beta.count'))->toBe("0\n");
    $ledger = json_decode((string) file_get_contents($fx['root'].'/tests/baseline/CHANGES.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($ledger['additions']['alpha']['count'])->toBe(1)->and($ledger['additions']['beta']['names'])->toBe(['P\\B::new']);
    expect(file_get_contents($fx['root'].'/tests/baseline/CHANGES.md'))->toContain('Additions');
});

it('keeps the repo suite baseline: an empty immutable original with provenance, current additions in the ledger', function (): void {
    $root = t_real_root().'/tests/baseline';
    $provenance = json_decode((string) file_get_contents($root.'/original/repo.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(file_get_contents($root.'/original/repo.list'))->toBe('')
        ->and(trim((string) file_get_contents($root.'/original/repo.count')))->toBe('0')
        ->and($provenance['introduced'])->toBe('P1.3');

    $ledger = json_decode((string) file_get_contents($root.'/CHANGES.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($ledger['additions']['repo']['count'])->toBeGreaterThan(0)->and($ledger['entries'])->toBeArray();
});


it('preserves integration discovery and schema partitions in the runner', function (): void {
    $inventory = json_decode((string) file_get_contents(t_real_root().'/tests/suites.json'), true, flags: JSON_THROW_ON_ERROR);
    $suite = $inventory['suites']['integration'];
    expect($suite['default'])->toBeTrue()->and($suite['discovery'])->toBe(['--list-tests', '--testsuite=integration']);
    $plan = t_plan(['sqlite', '-p', 'integration', '-j1']);
    expect($plan['suites'])->toBe(['integration'])
        ->and(array_values(array_unique(array_column($plan['steps'], 'partition'))))->toBe(array_column($suite['partitions'], 'key'));
    $files = array_merge(...array_column($suite['partitions'], 'files'));
    expect($files)->not->toBe([])->and(count($files))->toBe(count(array_unique($files)));
    foreach ($files as $file) {
        expect($file)->toStartWith('tests/Integration/')->and(is_file(t_real_root().'/'.$file))->toBeTrue();
    }
});

it('fingerprints schema semantics independently of testcase namespace and file path', function (): void {
    $fingerprint = static function (array $descriptor): string {
        $canonical = array_intersect_key($descriptor, array_flip(['providers', 'migrations', 'id_strategy', 'table_prefix', 'lifecycle', 'database', 'engines', 'skeleton_writer']));
        foreach (['providers', 'migrations', 'engines'] as $key) {
            sort($canonical[$key]);
        }
        ksort($canonical);
        return substr(hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 12);
    };
    $descriptor = ['providers' => ['B', 'A'], 'migrations' => ['database/b', 'database/a'], 'id_strategy' => 'bigint', 'table_prefix' => 'v_', 'lifecycle' => 'refresh', 'database' => true, 'engines' => ['mysql', 'sqlite'], 'skeleton_writer' => false];
    $hash = $fingerprint($descriptor);
    expect($fingerprint($descriptor + ['namespace' => 'Moved', 'file' => 'tests/Integration/TestCase.php']))->toBe($hash);
    $reordered = $descriptor;
    $reordered['providers'] = ['A', 'B'];
    expect($fingerprint($reordered))->toBe($hash);
    foreach (['providers' => ['C'], 'migrations' => ['database/c'], 'id_strategy' => 'ulid', 'lifecycle' => 'migrations'] as $key => $value) {
        $changed = $descriptor;
        $changed[$key] = $value;
        expect($fingerprint($changed))->not->toBe($hash, $key);
    }
});


it('keeps transferred post-original source cases out of destination additions', function (): void {
    $fx = t_baseline_fixture(['alpha' => ['P\A::one'], 'beta' => []], ['alpha' => ['P\A::one'], 'beta' => ['P\B::moved', 'P\B::fresh']]);
    t_ledger($fx, []);
    $path = $fx['root'].'/tests/baseline/CHANGES.json';
    $ledger = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $ledger['transfers'] = [['suite' => 'alpha', 'old' => 'P\A::added_after_original', 'new' => 'P\B::moved', 'new_suite' => 'beta', 'reason' => 'same current source case', 'decision' => 'PLAN4-D6']];
    file_put_contents($path, json_encode($ledger, JSON_THROW_ON_ERROR));
    [$code] = t_run(['baseline', '--write', '-p', 'alpha,beta'], t_env($fx), $fx['root']);
    $written = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($code)->toBe(0)->and($written['additions']['beta']['names'])->toBe(['P\B::fresh'])
        ->and(file_get_contents($fx['root'].'/tests/baseline/original/alpha.list'))->toBe("P\\A::one\n");
});
