<?php

declare(strict_types=1);

/*
 * scripts/test-select.php and `scripts/t --changed`, driven without Laravel, a database or a real Pest. Git behaviour
 * runs in throw-away fixture repositories (never in the live one); the real map is only ever read, through --paths.
 */

function s_root(): string
{
    return dirname(__DIR__, 2);
}

function s_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function s_write(string $root, string $path, string $contents = "x\n", bool $executable = false): void
{
    is_dir(dirname($root.'/'.$path)) || mkdir(dirname($root.'/'.$path), 0o777, true);
    file_put_contents($root.'/'.$path, $contents);
    $executable && chmod($root.'/'.$path, 0o755);
}

/** @return array{0: int, 1: string, 2: string} */
function s_exec(array $command, ?string $cwd = null, array $env = []): array
{
    $clean = array_filter(getenv(), static fn (string $key): bool => preg_match('/^(DB_|VAULTER_|TEST_|T_|TESTBENCH_|APP_BASE_PATH|PEST_FLAGS|GIT_)/', $key) !== 1, ARRAY_FILTER_USE_KEY);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, array_merge($clean, [
        'GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@example.invalid', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@example.invalid',
        'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null',
    ], $env));
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $out, $err];
}

function s_git(string $root, string ...$args): string
{
    [$code, $out, $err] = s_exec(['git', '-c', 'commit.gpgsign=false', ...$args], $root);
    expect($code)->toBe(0, 'git '.implode(' ', $args).": $err");

    return $out;
}

/**
 * Runs the selector; the plan is the decoded --json output.
 *
 * @param  array<int, string>                                $args
 * @return array{0: int, 1: array<string, mixed>, 2: string}
 */
function s_select(array $args, ?string $root = null): array
{
    [$code, $out, $err] = s_exec([PHP_BINARY, s_root().'/scripts/test-select.php', '--json', ...($root === null ? [] : ["--root=$root"]), ...$args]);
    $plan = json_decode($out, true);
    expect($plan)->toBeArray("selector printed no JSON (exit $code): $out $err");

    return [$code, $plan, $err];
}

function s_partition(string $key, array $files, bool $database = true): array
{
    return [
        'key' => $key, 'fingerprint' => 'x', 'lifecycle' => $database ? 'refresh' : 'none', 'database' => $database,
        'engines' => $database ? ['sqlite', 'pgsql', 'mysql'] : ['sqlite'], 'skeleton_writer' => false, 'empty_passes' => ['serial'], 'files' => $files,
    ];
}

/** @return array<string, mixed> */
function s_map(): array
{
    return [
        'version' => 1,
        'zones' => [
            'core' => ['children' => ['core-a', 'core-b'], 'suite' => 'core', 'uses' => []],
            'core-a' => ['parent' => 'core', 'suite' => 'core', 'src' => ['lib/core/a'], 'tests' => ['tests/core/a'], 'uses' => []],
            'core-b' => ['parent' => 'core', 'suite' => 'core', 'src' => ['lib/core/b'], 'tests' => ['tests/core/b'], 'uses' => ['core-a']],
            'pkg' => ['suite' => 'pkg', 'src' => ['lib/pkg'], 'tests' => ['tests/pkg'], 'uses' => ['core']],
            'solo' => ['suite' => 'solo', 'src' => ['lib/solo'], 'tests' => ['tests/solo'], 'uses' => []],
            'stand' => ['suite' => 'stand', 'explicit' => true, 'src' => ['lib/stand'], 'tests' => ['tests/stand'], 'uses' => ['core']],
        ],
        'harness' => [
            ['path' => 'tests/Pest.php', 'zones' => ['core']],
            ['path' => 'scripts/runner.sh', 'zones' => ['solo']],
        ],
        'database' => [],
        'tail' => ['docs/**', '**/README.md'],
    ];
}

/** @return array<string, mixed> */
function s_inventory(): array
{
    return ['version' => 1, 'suites' => [
        'core' => ['kind' => 'pest', 'cwd' => '.', 'vendor' => 'vendor/bin/pest', 'config' => 'phpunit.xml', 'tests_dir' => 'tests/core', 'path_base' => 'repo', 'default' => true, 'discovery' => ['--list-tests'],
            'partitions' => [s_partition('A', ['tests/core/a/OneTest.php', 'tests/core/a/DbTest.php', 'tests/core/b/TwoTest.php'])]],
        'pkg' => ['kind' => 'pest', 'cwd' => '.', 'vendor' => 'vendor/bin/pest', 'config' => 'phpunit.xml', 'tests_dir' => 'tests/pkg', 'path_base' => 'repo', 'default' => true, 'discovery' => ['--list-tests'],
            'partitions' => [s_partition('A', ['tests/pkg/PkgTest.php', 'tests/pkg/PkgDbTest.php'])]],
        'solo' => ['kind' => 'pest', 'cwd' => '.', 'vendor' => 'vendor/bin/pest', 'config' => 'phpunit.xml', 'tests_dir' => 'tests/solo', 'path_base' => 'repo', 'default' => true, 'discovery' => ['--list-tests'],
            'partitions' => [s_partition('A', ['tests/solo/SoloTest.php'], false)]],
        'stand' => ['kind' => 'pest', 'cwd' => '.', 'vendor' => 'vendor/bin/pest', 'config' => 'phpunit.xml', 'tests_dir' => 'tests/stand', 'path_base' => 'repo', 'default' => false, 'discovery' => ['--list-tests'],
            'partitions' => [s_partition('A', ['tests/stand/StandTest.php'])]],
    ]];
}

function s_fake_pest(): string
{
    return <<<'PHP'
        <?php
        $args = $argv;
        array_shift($args);
        preg_match_all('#<file>([^<]+)</file>#', (string) file_get_contents(substr(array_values(array_filter($args, fn ($a) => str_starts_with($a, '--configuration=')))[0] ?? '--configuration=/dev/null', 16)), $m);
        file_put_contents((string) getenv('T_LOG'), json_encode(['args' => $args, 'files' => $m[1]])."\n", FILE_APPEND);
        if (getenv('T_FAKE_FAIL')) {
            echo "\n  Tests:    1 failed\n";
            exit(1);
        }
        echo "\n  Tests:    3 passed\n";
        PHP;
}

/**
 * A fixture repository: map, inventory, sources and tests, one commit on `main`, then `feature` checked out.
 *
 * @return array{root: string, log: string}
 */
function s_repo(?array $map = null): array
{
    $root = sys_get_temp_dir().'/vaulter-select-fixture-'.bin2hex(random_bytes(4));
    mkdir($root, 0o777, true);
    register_shutdown_function(static fn () => s_rmdir($root));

    s_write($root, 'tests/zones.json', json_encode($map ?? s_map(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    s_write($root, 'tests/suites.json', json_encode(s_inventory(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    s_write($root, 'tests/Pest.php', "<?php\n");
    s_write($root, 'scripts/runner.sh', "#!/bin/sh\n", true);
    s_write($root, 'scripts/php84', "#!/usr/bin/env bash\nexec \"\$@\"\n", true);
    s_write($root, 'phpunit.xml', '<?xml version="1.0"?>'."\n".'<phpunit bootstrap="vendor/autoload.php">'."\n".'    <testsuites>'."\n".'        <testsuite name="x"><directory>tests</directory></testsuite>'."\n".'    </testsuites>'."\n".'</phpunit>'."\n");
    s_write($root, 'vendor/bin/pest', s_fake_pest(), true);
    foreach (['lib/core/a/One.php', 'lib/core/b/Two.php', 'lib/pkg/Pkg.php', 'lib/solo/Solo.php', 'lib/stand/Stand.php', 'docs/guide.md', 'README.md',
        'tests/core/a/OneTest.php', 'tests/core/a/DbTest.php', 'tests/core/b/TwoTest.php', 'tests/pkg/PkgTest.php', 'tests/pkg/PkgDbTest.php', 'tests/solo/SoloTest.php', 'tests/stand/StandTest.php'] as $file) {
        s_write($root, $file, "<?php\n");
    }
    s_git($root, 'init', '-q', '-b', 'main');
    s_git($root, 'add', '.');
    s_git($root, 'commit', '-q', '-m', 'base');
    s_git($root, 'checkout', '-q', '-b', 'feature');

    return ['root' => $root, 'log' => $root.'/calls.log'];
}

function s_commit(string $root, string $message): void
{
    s_git($root, 'add', '.');
    s_git($root, 'commit', '-q', '-m', $message);
}

function s_runs(array $plan): array
{
    return array_map(static fn (array $run): string => $run['command'], $plan['runs']);
}

// ---------------------------------------------------------------------------------------------------------------------
// git input
// ---------------------------------------------------------------------------------------------------------------------

it('plans a committed change of one zone with its consumers, and keeps an explicit zone out of the consumers', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'lib/core/a/One.php', "<?php // changed\n");
    s_commit($fx['root'], 'a');

    [$code, $plan] = s_select(['--base=main'], $fx['root']);

    expect($code)->toBe(0)->and($plan['status'])->toBe('ok')
        ->and($plan['touched'])->toBe(['core-a'])
        ->and($plan['consumers'])->toBe(['core-b', 'pkg'])
        ->and($plan['affected'])->not->toContain('stand')
        ->and(s_runs($plan))->toBe(['scripts/t sqlite -p core', 'scripts/t sqlite -p pkg']);
});

it('selects one sub-zone of a suite by its zone group when its siblings are untouched', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'lib/core/b/Two.php', "<?php // changed\n");
    s_commit($fx['root'], 'b');

    [, $plan] = s_select(['--base=main'], $fx['root']);

    expect(s_runs($plan))->toBe(['scripts/t sqlite -p core --group=zone:core-b', 'scripts/t sqlite -p pkg'])
        ->and($plan['runs'][0]['groups'])->toBe(['zone:core-b']);
});

it('reads a multi-commit branch from the merge-base, ignoring what the base gained afterwards', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'lib/solo/Solo.php', "<?php // one\n");
    s_commit($fx['root'], 'one');
    s_write($fx['root'], 'lib/pkg/Pkg.php', "<?php // two\n");
    s_commit($fx['root'], 'two');
    s_git($fx['root'], 'checkout', '-q', 'main');
    s_write($fx['root'], 'lib/core/a/One.php', "<?php // main moved on\n");
    s_commit($fx['root'], 'main moved');
    s_git($fx['root'], 'checkout', '-q', 'feature');

    [, $plan] = s_select(['--base=main'], $fx['root']);
    expect(array_column($plan['changed'], 'path'))->toBe(['lib/pkg/Pkg.php', 'lib/solo/Solo.php'])
        ->and($plan['merge_base'])->toBe(trim(s_git($fx['root'], 'merge-base', 'main', 'HEAD')));

    [, $last] = s_select(['--base=HEAD~1'], $fx['root']);
    expect(array_column($last['changed'], 'path'))->toBe(['lib/pkg/Pkg.php']);
});

it('adds staged, unstaged and untracked files to the committed diff', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'lib/solo/Solo.php', "<?php // unstaged\n");
    s_write($fx['root'], 'lib/pkg/Pkg.php', "<?php // staged\n");
    s_git($fx['root'], 'add', 'lib/pkg/Pkg.php');
    s_write($fx['root'], 'lib/core/b/Fresh.php', "<?php\n");

    [$code, $plan] = s_select(['--base=HEAD'], $fx['root']);

    expect($code)->toBe(0)->and(array_column($plan['changed'], 'path'))->toBe(['lib/core/b/Fresh.php', 'lib/pkg/Pkg.php', 'lib/solo/Solo.php'])
        ->and($plan['touched'])->toEqualCanonicalizing(['core-b', 'pkg', 'solo']);
});

it('counts a rename for both paths, staged or committed', function (): void {
    $fx = s_repo();
    s_git($fx['root'], 'mv', 'lib/core/a/One.php', 'lib/solo/One.php');

    [$code, $plan] = s_select(['--base=HEAD'], $fx['root']);
    expect($code)->toBe(0)->and($plan['touched'])->toEqualCanonicalizing(['core-a', 'solo'])
        ->and(array_column($plan['changed'], 'path'))->toBe(['lib/core/a/One.php', 'lib/solo/One.php']);

    s_commit($fx['root'], 'rename');
    [, $committed] = s_select(['--base=main'], $fx['root']);
    expect($committed['touched'])->toEqualCanonicalizing(['core-a', 'solo']);
});

it('counts a deletion, unstaged or committed, as a change of its zone', function (): void {
    $fx = s_repo();
    unlink($fx['root'].'/lib/pkg/Pkg.php');
    [, $plan] = s_select(['--base=HEAD'], $fx['root']);
    expect($plan['touched'])->toBe(['pkg'])->and($plan['changed'][0]['status'])->toBe('D');

    s_commit($fx['root'], 'delete');
    [, $committed] = s_select(['--base=main'], $fx['root']);
    expect($committed['touched'])->toBe(['pkg']);
});

it('reports no changes honestly: a plan of zero runs, never a pass', function (): void {
    $fx = s_repo();

    [$code, $plan] = s_select(['--base=HEAD'], $fx['root']);

    expect($code)->toBe(0)->and($plan['status'])->toBe('no_changes')->and($plan['runs'])->toBe([]);
});

it('refuses a base that does not resolve and a root that is not a work tree, naming the fix', function (): void {
    $fx = s_repo();
    [$code, $plan] = s_select(['--base=nope'], $fx['root']);
    expect($code)->toBe(2)->and($plan['errors'][0])->toContain('git fetch --depth=1 origin nope')->and($plan['runs'])->toBe([]);

    $bare = sys_get_temp_dir().'/vaulter-select-nogit-'.bin2hex(random_bytes(4));
    mkdir($bare.'/tests', 0o777, true);
    register_shutdown_function(static fn () => s_rmdir($bare));
    file_put_contents($bare.'/tests/zones.json', json_encode(s_map()));
    [$code, $plan] = s_select([], $bare);
    expect($code)->toBe(2)->and($plan['errors'][0])->toContain('not a git work tree');
});

// ---------------------------------------------------------------------------------------------------------------------
// harness, unknown, prose
// ---------------------------------------------------------------------------------------------------------------------

it('refuses a harness change with the full affected set of its zones and their consumers', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'tests/Pest.php', "<?php // harness\n");

    [$code, $plan] = s_select(['--base=HEAD'], $fx['root']);

    expect($code)->toBe(2)->and($plan['status'])->toBe('harness')
        ->and($plan['affected'])->toBe(['core-a', 'core-b', 'pkg'])
        ->and(s_runs($plan))->toBe(['scripts/t sqlite -p core', 'scripts/t sqlite -p pkg'])
        ->and(array_keys($plan['classified']['harness']))->toBe(['tests/Pest.php']);
});

it('refuses a dirty harness script even against --base=HEAD, and still plans', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'scripts/runner.sh', "#!/bin/sh\n# edited\n", true);

    [$code, $plan] = s_select(['--base=HEAD'], $fx['root']);

    expect($code)->toBe(2)->and($plan['status'])->toBe('harness')->and(s_runs($plan))->toBe(['scripts/t sqlite -p solo']);
});

it('refuses an unknown path with the full conservative plan, alone or mixed with a known one', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'stray/Thing.php', "<?php\n");

    [$code, $plan] = s_select(['--base=HEAD'], $fx['root']);
    expect($code)->toBe(2)->and($plan['status'])->toBe('unknown')->and($plan['classified']['unknown'])->toBe(['stray/Thing.php'])
        ->and(s_runs($plan))->toBe(['scripts/t sqlite -p core', 'scripts/t sqlite -p pkg', 'scripts/t sqlite -p solo']);

    s_write($fx['root'], 'lib/solo/Solo.php', "<?php // known too\n");
    [$code, $mixed] = s_select(['--base=HEAD'], $fx['root']);
    expect($code)->toBe(2)->and($mixed['classified']['zones'])->toBe(['lib/solo/Solo.php' => 'solo'])
        ->and(s_runs($mixed))->toBe(s_runs($plan));
});

it('skips a prose-only change as docs_only with no runs, and a prose file mixed with code does not hide the code', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'docs/guide.md', "# changed\n");
    s_write($fx['root'], 'lib/solo/README.md', "# readme\n");

    [$code, $plan] = s_select(['--base=HEAD'], $fx['root']);
    expect($code)->toBe(0)->and($plan['status'])->toBe('docs_only')->and($plan['runs'])->toBe([]);

    s_write($fx['root'], 'lib/solo/Solo.php', "<?php // code\n");
    [, $mixed] = s_select(['--base=HEAD'], $fx['root']);
    expect($mixed['status'])->toBe('ok')->and(s_runs($mixed))->toBe(['scripts/t sqlite -p solo']);
});

it('allowlists only prose in the real map: AGENTS, CLAUDE, workflows and root configs are not docs', function (): void {
    [$code, $plan] = s_select(['--paths=docs/guide/index.md,plans/x/log.jsonl,README.md,CONTRIBUTING.md,packages/azgard/README.md']);
    expect($code)->toBe(0)->and($plan['status'])->toBe('docs_only')->and($plan['runs'])->toBe([]);

    foreach (['AGENTS.md', 'CLAUDE.md', '.github/workflows/ci.yml', 'CHANGELOG.md', 'packages/core/phpstan.neon'] as $path) {
        [$code, $plan] = s_select(["--paths=$path"]);
        expect($code)->toBe(2, $path)->and($plan['status'])->toBe('unknown', $path)->and($plan['runs'])->not->toBe([], $path);
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// --quick, --db, broken maps
// ---------------------------------------------------------------------------------------------------------------------

it('keeps only the touched zones for --quick on a clean change, but never narrows a refused plan', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'lib/core/a/One.php', "<?php // changed\n");

    [, $quick] = s_select(['--base=HEAD', '--quick'], $fx['root']);
    expect($quick['affected'])->toBe(['core-a'])->and($quick['consumers'])->toBe([])->and(s_runs($quick))->toBe(['scripts/t sqlite -p core --group=zone:core-a']);

    s_write($fx['root'], 'tests/Pest.php', "<?php // harness\n");
    [$code, $refused] = s_select(['--base=HEAD', '--quick'], $fx['root']);
    expect($code)->toBe(2)->and($refused['affected'])->toBe(['core-a', 'core-b', 'pkg']);
});

it('intersects the database classification with the affected zones for --db', function (): void {
    $map = s_map();
    $map['database'] = ['tests/core/a/DbTest.php', 'tests/pkg/PkgDbTest.php', 'tests/solo'];
    $fx = s_repo($map);
    s_write($fx['root'], 'lib/core/a/One.php', "<?php // changed\n");

    [$code, $plan] = s_select(['--base=HEAD', '--db=pgsql'], $fx['root']);

    expect($code)->toBe(0)
        ->and(s_runs($plan))->toBe([
            'scripts/t pgsql -p core -- tests/core/a/DbTest.php',
            'scripts/t pgsql -p pkg -- tests/pkg/PkgDbTest.php',
        ])
        ->and($plan['runs'][0]['paths'])->toBe(['tests/core/a/DbTest.php']);

    s_write($fx['root'], 'lib/solo/Solo.php', "<?php // changed\n");
    [, $solo] = s_select(['--base=HEAD', '--db=mysql', '--quick'], $fx['root']);
    expect(array_map(static fn (array $r): string => $r['suite'], $solo['runs']))->toBe(['core', 'solo']);
});

it('says so when the affected zones hold no database test, instead of passing silently', function (): void {
    $map = s_map();
    $map['database'] = ['tests/core/a/DbTest.php'];
    $fx = s_repo($map);
    s_write($fx['root'], 'lib/solo/Solo.php', "<?php // changed\n");

    [$code, $plan] = s_select(['--base=HEAD', '--db=pgsql'], $fx['root']);

    expect($code)->toBe(0)->and($plan['status'])->toBe('no_database_tests')->and($plan['runs'])->toBe([])
        ->and($plan['notes'][0])->toContain('solo: no database-classified test');
});

it('refuses --db while the database classification is empty and rejects a bad engine', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'lib/solo/Solo.php', "<?php // changed\n");

    [$code, $plan] = s_select(['--base=HEAD', '--db=pgsql'], $fx['root']);
    expect($code)->toBe(2)->and($plan['status'])->toBe('invalid_request')->and($plan['errors'][0])->toContain('database classification');

    [$code, $plan] = s_select(['--base=HEAD', '--db=oracle'], $fx['root']);
    expect($code)->toBe(2)->and($plan['errors'][0])->toContain('pgsql or mysql');
});

it('refuses a map with an unknown dependency, a cycle, a self reference, a nested tests entry or a shared entry', function (): void {
    $cases = [
        'unknown dependency' => [static function (array &$m): void {
            $m['zones']['solo']['uses'] = ['ghost'];
        }, 'uses unknown zone ghost'],
        'self reference' => [static function (array &$m): void {
            $m['zones']['solo']['uses'] = ['solo'];
        }, 'uses itself'],
        'cycle' => [static function (array &$m): void {
            $m['zones']['core-a']['uses'] = ['pkg'];
        }, 'dependency cycle'],
        'child uses its parent' => [static function (array &$m): void {
            $m['zones']['core-a']['uses'] = ['core'];
        }, 'its own parent'],
        'nested tests entry' => [static function (array &$m): void {
            $m['zones']['solo']['tests'] = ['tests/core/a/deeper'];
        }, 'nested in tests/core/a'],
        'shared src entry' => [static function (array &$m): void {
            $m['zones']['solo']['src'] = ['lib/pkg'];
        }, 'owned by several zones'],
        'unknown suite' => [static function (array &$m): void {
            $m['zones']['solo']['suite'] = 'ghost';
        }, 'not in the suite inventory'],
        'harness zone' => [static function (array &$m): void {
            $m['harness'][0]['zones'] = ['ghost'];
        }, 'unknown zone ghost'],
        'orphan child' => [static function (array &$m): void {
            $m['zones']['core']['children'] = ['core-a'];
        }, 'does not list it as a child'],
    ];

    foreach ($cases as $name => [$break, $message]) {
        $map = s_map();
        $break($map);
        $fx = s_repo($map);
        s_write($fx['root'], 'lib/solo/Solo.php', "<?php // changed\n");

        [$code, $plan] = s_select(['--base=HEAD'], $fx['root']);
        expect($code)->toBe(2, $name)->and($plan['status'])->toBe('invalid_map', $name)->and($plan['runs'])->toBe([], $name)
            ->and(implode("\n", $plan['errors']))->toContain($message);
    }
});

it('refuses an unknown argument and an unreadable map instead of guessing', function (): void {
    [$code, $plan] = s_select(['--bogus']);
    expect($code)->toBe(2)->and($plan['errors'][0])->toContain('unknown argument');

    [$code, $plan] = s_select(['--map='.sys_get_temp_dir().'/vaulter-no-such-map.json', '--paths=x']);
    expect($code)->toBe(2)->and($plan['status'])->toBe('invalid_map');
});

// ---------------------------------------------------------------------------------------------------------------------
// the real map
// ---------------------------------------------------------------------------------------------------------------------

it('plans a media change for media and its Composer consumers only', function (): void {
    [$code, $plan] = s_select(['--paths=packages/media/src/Cas/BlobStore.php']);

    expect($code)->toBe(0)->and($plan['touched'])->toBe(['media'])->and($plan['affected'])->toBe(['media', 'drive', 'documents', 'filament'])
        ->and($plan['affected'])->not->toContain('azgard')
        ->and(s_runs($plan))->toBe(['scripts/t sqlite -p media', 'scripts/t sqlite -p drive', 'scripts/t sqlite -p documents', 'scripts/t sqlite -p filament']);
});

it('plans a core sub-zone change by group and pulls the dependent sub-zones and packages, never load', function (): void {
    [$code, $plan] = s_select(['--paths=packages/core/src/Sharing/ShareResolver.php']);

    expect($code)->toBe(0)->and($plan['touched'])->toBe(['core-sharing'])
        ->and($plan['affected'])->toBe(['core-sharing', 'core-http', 'core-observability', 'media', 'drive', 'documents', 'azgard', 'filament'])
        ->and($plan['affected'])->not->toContain('load')
        ->and($plan['runs'][0]['command'])->toBe('scripts/t sqlite -p core --group=zone:core-sharing,zone:core-http,zone:core-observability');

    [, $quick] = s_select(['--paths=packages/core/src/Sharing/ShareResolver.php', '--quick']);
    expect($quick['affected'])->toBe(['core-sharing']);
});

it('plans a Node or load change for its own suite only', function (): void {
    foreach ([['hocuspocus-server/src/index.js', 'hub'], ['packages/vue/src/plugin.ts', 'vue'], ['load/app/Foo.php', 'load']] as [$path, $suite]) {
        [$code, $plan] = s_select(["--paths=$path"]);
        expect($code)->toBe(0, $path)->and($plan['affected'])->toBe([$suite], $path);
    }
    [, $hub] = s_select(['--paths=hocuspocus-server/src/index.js']);
    expect(s_runs($hub))->toBe(['scripts/t -p hub']);
});

it('refuses a core TestCase change with the whole of core and its consumers', function (): void {
    [$code, $plan] = s_select(['--paths=packages/core/tests/TestCase.php']);

    expect($code)->toBe(2)->and($plan['status'])->toBe('harness')
        ->and($plan['affected'])->toBe(['core-kernel', 'core-access', 'core-sharing', 'core-events-webhooks', 'core-http', 'core-schema', 'core-observability', 'media', 'drive', 'documents', 'azgard', 'filament'])
        ->and(s_runs($plan)[0])->toBe('scripts/t sqlite -p core');
});

it('refuses a change of the runner, the selector or the map itself as harness', function (): void {
    foreach (['scripts/t', 'scripts/test-select.php', 'tests/zones.json', 'tests/suites.json', 'tests/Pest.php', 'phpunit.xml'] as $path) {
        [$code, $plan] = s_select(["--paths=$path"]);
        expect($code)->toBe(2, $path)->and($plan['status'])->toBe('harness', $path);
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// scripts/t --changed
// ---------------------------------------------------------------------------------------------------------------------

/**
 * @param  array<int, string>                  $args
 * @param  array<string, string>               $env
 * @return array{0: int, 1: string, 2: string}
 */
function s_t(array $fx, array $args, array $env = []): array
{
    return s_exec(['bash', s_root().'/scripts/t', ...$args], $fx['root'], $env + ['T_ROOT' => $fx['root'], 'T_LOG' => $fx['log'], 'T_TMP' => $fx['root'].'/var/t', 'T_CPUS' => '2']);
}

function s_calls(array $fx): array
{
    return is_file($fx['log'])
        ? array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_filter(explode("\n", (string) file_get_contents($fx['log']))))
        : [];
}

it('runs one scripts/t run per affected suite for --changed', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'lib/solo/Solo.php', "<?php // changed\n");

    [$code, $out] = s_t($fx, ['--changed', '--base=HEAD']);

    expect($code)->toBe(0)->and($out)->toContain('touched:   solo');
    $calls = s_calls($fx);
    expect($calls)->toHaveCount(1)->and($calls[0]['files'])->toBe([$fx['root'].'/tests/solo/SoloTest.php']);
});

it('runs a core sub-zone through its zone group', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'lib/core/b/Two.php', "<?php // changed\n");

    [$code] = s_t($fx, ['--changed', '--base=HEAD', '--quick']);

    $calls = s_calls($fx);
    expect($code)->toBe(0)->and($calls)->toHaveCount(1)->and($calls[0]['args'])->toContain('--group=zone:core-b');
});

it('plans --changed --dry-run completely and executes nothing, also when the plan is refused', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'lib/core/a/One.php', "<?php // changed\n");

    [$code, $out] = s_t($fx, ['--changed', '--base=HEAD', '--dry-run', '--json']);
    $plan = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    expect($code)->toBe(0)->and($plan['commands'])->toBe(['scripts/t sqlite -p core', 'scripts/t sqlite -p pkg'])
        ->and($plan['plans'])->toHaveCount(2)->and($plan['plans'][0]['suites'])->toBe(['core'])
        ->and(s_calls($fx))->toBe([]);

    s_write($fx['root'], 'stray/Thing.php', "<?php\n");
    [$code, $out] = s_t($fx, ['--changed', '--base=HEAD', '--dry-run', '--json']);
    $refused = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    expect($code)->toBe(2)->and($refused['status'])->toBe('unknown')->and($refused['commands'])->toBe(['scripts/t sqlite -p core', 'scripts/t sqlite -p pkg', 'scripts/t sqlite -p solo'])
        ->and(s_calls($fx))->toBe([]);

    [$code, $text] = s_t($fx, ['--changed', '--base=HEAD', '--dry-run']);
    expect($code)->toBe(2)->and($text)->toContain('status:    unknown (exit 2)')->and($text)->toContain('run:       scripts/t sqlite -p solo');
});

it('refuses --changed for an unknown or harness path before any test process starts', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'stray/Thing.php', "<?php\n");
    s_write($fx['root'], 'lib/solo/Solo.php', "<?php // known too\n");

    [$code, , $err] = s_t($fx, ['--changed', '--base=HEAD']);
    expect($code)->toBe(2)->and($err)->toContain('refused (unknown)')->and(s_calls($fx))->toBe([]);

    unlink($fx['root'].'/stray/Thing.php');
    s_write($fx['root'], 'scripts/runner.sh', "#!/bin/sh\n# edited\n", true);
    [$code, , $err] = s_t($fx, ['--changed', '--base=HEAD']);
    expect($code)->toBe(2)->and($err)->toContain('refused (harness)')->and(s_calls($fx))->toBe([]);
});

it('says there is nothing to run for prose or no change, and never calls that a pass of tests', function (): void {
    $fx = s_repo();

    [$code, , $err] = s_t($fx, ['--changed', '--base=HEAD']);
    expect($code)->toBe(0)->and($err)->toContain('no tests to run (no_changes)')->and(s_calls($fx))->toBe([]);

    s_write($fx['root'], 'docs/guide.md', "# changed\n");
    [$code, $out, $err] = s_t($fx, ['--changed', '--base=HEAD']);
    expect($code)->toBe(0)->and($out)->toContain('status:    docs_only')->and($err)->toContain('no tests to run (docs_only)')->and(s_calls($fx))->toBe([]);
});

it('fails --changed when a planned run fails', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'lib/solo/Solo.php', "<?php // changed\n");

    [$code] = s_t($fx, ['--changed', '--base=HEAD'], ['T_FAKE_FAIL' => '1']);

    expect($code)->toBe(1);
});

it('refuses --changed combined with a selector of its own, and its options without --changed', function (): void {
    $fx = s_repo();

    foreach ([['-p', 'solo'], ['pgsql'], ['--group=database'], ['--full'], ['--', 'tests/solo/SoloTest.php']] as $extra) {
        [$code, , $err] = s_t($fx, ['--changed', '--base=HEAD', ...$extra]);
        expect($code)->toBe(2, implode(' ', $extra))->and($err)->toContain('do not combine');
    }
    foreach ([['--quick'], ['--base=main'], ['--db=pgsql']] as $extra) {
        [$code, , $err] = s_t($fx, [...$extra, '--dry-run']);
        expect($code)->toBe(2, implode(' ', $extra))->and($err)->toContain('belong to --changed');
    }
    [$code, , $err] = s_t($fx, ['--changed=main']);
    expect($code)->toBe(2)->and($err)->toContain('takes no value');
});

it('routes --db through the database classification and refuses it while that is empty', function (): void {
    $fx = s_repo();
    s_write($fx['root'], 'lib/core/a/One.php', "<?php // changed\n");

    [$code, $out] = s_t($fx, ['--changed', '--base=HEAD', '--db=pgsql', '--dry-run']);
    expect($code)->toBe(2)->and($out)->toContain('database classification');

    $map = s_map();
    $map['database'] = ['tests/core/a/DbTest.php'];
    s_write($fx['root'], 'tests/zones.json', json_encode($map));
    s_git($fx['root'], 'add', '.');
    s_git($fx['root'], 'commit', '-q', '-m', 'classify');
    s_write($fx['root'], 'lib/core/a/One.php', "<?php // changed again\n");
    [$code, $out] = s_t($fx, ['--changed', '--base=HEAD', '--db=pgsql', '--dry-run', '--json']);
    $plan = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    expect($code)->toBe(0)->and($plan['commands'])->toBe(['scripts/t pgsql -p core -- tests/core/a/DbTest.php'])
        ->and($plan['plans'][0]['engines'])->toBe(['pgsql']);
});
