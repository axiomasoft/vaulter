<?php

declare(strict_types=1);

/*
 * tests/zones.json is the single source of test zones. This proves the classification is COMPLETE — every source root and
 * every test file of every suite belongs to exactly one leaf zone, the graph is sound, the paths exist — and nothing more:
 * it does not prove that `uses` is the true dependency graph (it is the Composer graph plus a coarse leaf graph in core).
 */

function z_root(): string
{
    return dirname(__DIR__, 2);
}

/** @return array<string, mixed> */
function z_json(string $file): array
{
    return json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array{0: int, 1: array<string, mixed>} */
function z_select(array $args): array
{
    $process = proc_open([PHP_BINARY, z_root().'/scripts/test-select.php', '--json', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, z_root());
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);

    return [proc_close($process), json_decode($out, true, flags: JSON_THROW_ON_ERROR)];
}

/** @return array<int, string> repo-relative files under $dir whose name matches $pattern (fnmatch), vendor and node_modules skipped */
function z_files(string $dir, string $pattern): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(z_root().'/'.$dir, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $file): bool => !in_array($file->getFilename(), ['vendor', 'node_modules'], true),
    ));
    foreach ($iterator as $file) {
        if (fnmatch($pattern, $file->getFilename())) {
            $found[] = substr($file->getPathname(), strlen(z_root()) + 1);
        }
    }
    sort($found);

    return $found;
}

/** @return array<string, array<int, string>> suite => every test file the suite runs, from disk */
function z_test_files(): array
{
    $inventory = z_json(z_root().'/tests/suites.json');
    $files = [];
    foreach ($inventory['suites'] as $name => $suite) {
        $files[$name] = match (true) {
            $suite['kind'] === 'pest' => z_files($suite['tests_dir'], '*Test.php'),
            $name === 'hub' => [...z_files('packages/collaboration/hub/test', '*.test.js'), ...z_files('packages/collaboration/hub/test/e2e', '*.e2e.js')],
            $name === 'vue' => z_files('packages/vue/tests', '*.test.ts'),
            $name === 'vue-collaboration' => z_files('packages/vue-collaboration/tests', '*.test.ts'),
            default => [],
        };
        $files[$name] = array_values(array_unique($files[$name]));
    }

    return $files;
}

/** @return array<int, string> every source root: a package's src entries, the hub, the Vue client, the load app */
function z_src_roots(): array
{
    $roots = [];
    foreach (glob(z_root().'/packages/*/src/*') ?: [] as $entry) {
        $roots[] = substr($entry, strlen(z_root()) + 1);
    }
    foreach (['packages/collaboration/hub/src', 'packages/vue/src', 'packages/vue-collaboration/src', 'load/app'] as $root) {
        $roots[] = $root;
    }
    sort($roots);

    return $roots;
}

/**
 * Everything wrong with a map, judged against the real tree. Empty = a map that tells the truth.
 *
 * @return array<int, string>
 */
function z_problems(string $mapFile): array
{
    [$code, $check] = z_select(['--check-map', "--map=$mapFile"]);
    if ($code !== 0) {
        return $check['errors'];
    }

    $map = z_json($mapFile);
    $inventory = z_json(z_root().'/tests/suites.json');
    $problems = [];
    $leaves = array_filter($map['zones'], static fn (array $zone): bool => !isset($zone['children']));

    // 1. every source root belongs to a leaf, and is not swallowed by the harness or the prose allowlist
    $roots = z_src_roots();
    [, $classified] = z_select(["--map=$mapFile", '--paths='.implode(',', $roots)]);
    foreach ($roots as $root) {
        if (!isset($classified['classified']['zones'][$root])) {
            $problems[] = "source root $root belongs to no zone";
        }
    }

    // 2. every test file of every suite belongs to exactly one leaf, and that leaf runs in the file's suite
    $testFiles = z_test_files();
    $all = array_merge(...array_values($testFiles));
    [, $classified] = z_select(["--map=$mapFile", '--paths='.implode(',', $all)]);
    foreach ($testFiles as $suite => $files) {
        foreach ($files as $file) {
            $zone = $classified['classified']['zones'][$file] ?? null;
            if ($zone === null) {
                $problems[] = "test file $file ($suite) belongs to no zone";
            } elseif (($leaves[$zone]['suite'] ?? null) !== $suite) {
                $problems[] = "test file $file runs in suite $suite but zone $zone says ".($leaves[$zone]['suite'] ?? '?');
            }
        }
    }

    // 3. the inventory's partitions agree with the zones: every inventoried file of a suite is a test file of that suite
    foreach ($inventory['suites'] as $suite => $definition) {
        foreach ($definition['partitions'] ?? [] as $partition) {
            foreach ($partition['files'] as $file) {
                if (!in_array($file, $testFiles[$suite] ?? [], true)) {
                    $problems[] = "partition file $file of $suite is not a test file of the suite";
                }
            }
        }
        if (array_filter($leaves, static fn (array $zone): bool => $zone['suite'] === $suite) === []) {
            $problems[] = "suite $suite is represented by no zone";
        }
    }

    // 4. no dead entry: every tests entry holds at least one test file
    foreach ($leaves as $name => $zone) {
        foreach ($zone['tests'] as $entry) {
            $holds = array_filter($testFiles[$zone['suite']] ?? [], static fn (string $file): bool => $file === $entry || str_starts_with($file, $entry.'/'));
            if ($holds === []) {
                $problems[] = "tests entry $entry of zone $name holds no test file of suite {$zone['suite']}";
            }
        }
    }

    return $problems;
}

/** @return array<string, mixed> */
function z_broken(callable $break): array
{
    $map = z_json(z_root().'/tests/zones.json');
    $break($map);
    $file = sys_get_temp_dir().'/vaulter-zones-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($file, json_encode($map, JSON_PRETTY_PRINT));
    register_shutdown_function(static fn () => @unlink($file));

    return ['file' => $file, 'problems' => z_problems($file)];
}

it('keeps the real map complete: every source root and test file is in exactly one zone', function (): void {
    expect(z_problems(z_root().'/tests/zones.json'))->toBe([]);
});

it('holds every core test file in one core sub-zone, exactly once', function (): void {
    $map = z_json(z_root().'/tests/zones.json');
    $core = array_values(array_filter($map['zones'], static fn (array $zone): bool => ($zone['parent'] ?? null) === 'core'));
    $files = z_test_files()['core'];

    $owners = [];
    foreach ($core as $zone) {
        foreach ($zone['tests'] as $entry) {
            foreach ($files as $file) {
                if ($file === $entry || str_starts_with($file, $entry.'/')) {
                    $owners[$file][] = $entry;
                }
            }
        }
    }
    expect(array_keys($owners))->toEqualCanonicalizing($files)
        ->and(array_filter($owners, static fn (array $entries): bool => count($entries) !== 1))->toBe([])
        ->and(count($core))->toBeBetween(5, 9);
});

it('states the zone graph as the Composer graph for packages', function (): void {
    $map = z_json(z_root().'/tests/zones.json');
    $declared = static fn (string $zone): array => $map['zones'][$zone]['uses'];

    expect($declared('media'))->toBe(['core'])
        ->and($declared('drive'))->toBe(['core', 'media'])
        ->and($declared('documents'))->toContain('core', 'media', 'drive')
        ->and($declared('azgard'))->toBe(['core', 'media', 'drive'])
        ->and($declared('filament'))->toContain('core', 'drive');

    foreach (['media', 'drive', 'documents', 'azgard', 'filament'] as $package) {
        $composer = z_json(z_root().'/packages/'.$package.'/composer.json');
        $required = [];
        foreach (array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []) as $name => $_) {
            if (str_starts_with($name, 'axioma-studio/vaulter-')) {
                $required[] = substr($name, strlen('axioma-studio/vaulter-'));
            }
        }
        foreach ($required as $dependency) {
            expect($declared($package))->toContain($dependency);
        }
    }
});

it('tags every core sub-zone through the Pest group zone:<name>, and the groups partition the core suite', function (): void {
    $map = z_json(z_root().'/tests/zones.json');
    $pest = file_get_contents(z_root().'/tests/Pest.php');
    expect($pest)->toContain("'zone:'.\$zoneName")->and($pest)->toContain('zones.json');

    $listed = static function (string $group): array {
        $process = proc_open([PHP_BINARY, 'vendor/bin/pest', '--list-tests', '--testsuite=core', "--group=$group"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, z_root(), array_filter(getenv(), static fn (string $key): bool => !str_starts_with($key, 'DB_'), ARRAY_FILTER_USE_KEY));
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);
        preg_match_all('/^ - (\S+)/m', $out, $m);

        return array_unique(array_map(static fn (string $name): string => strstr($name, '::', true) ?: $name, $m[1]));
    };

    // a small zone and a two-directory zone keep this cheap; the full partition was measured once when the map was written
    foreach (['core-modules', 'core-operations'] as $zone) {
        $classes = $listed("zone:$zone");
        expect($classes)->not->toBe([]);
        foreach ($classes as $class) {
            $path = 'packages/core/tests/'.str_replace('\\', '/', substr($class, strlen('P\\Packages\\core\\tests\\'))).'.php';
            expect(array_filter($map['zones'][$zone]['tests'], static fn (string $entry): bool => $path === $entry || str_starts_with($path, $entry.'/')))->not->toBe([], "$class is tagged zone:$zone but lives outside its tests");
        }
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// the check has to fail on a spoiled map, not only pass on a good one
// ---------------------------------------------------------------------------------------------------------------------

it('goes red when a source root loses its zone', function (): void {
    $broken = z_broken(static function (array &$map): void {
        $map['zones']['core-kernel']['src'] = array_values(array_diff($map['zones']['core-kernel']['src'], ['packages/core/src/Support']));
    });

    expect($broken['problems'])->toContain('source root packages/core/src/Support belongs to no zone');
});

it('goes red when a test directory loses its zone', function (): void {
    $broken = z_broken(static function (array &$map): void {
        $map['zones']['integration']['tests'] = ['tests/Integration/Operations'];
    });

    expect($broken['problems'])->toContain('test file tests/Integration/Idempotency/IdempotencyContractTest.php (integration) belongs to no zone');
});

it('goes red when a test directory is claimed by a zone of another suite', function (): void {
    $broken = z_broken(static function (array &$map): void {
        $map['zones']['azgard']['suite'] = 'media';
    });

    expect(implode("\n", $broken['problems']))->toContain('runs in suite azgard but zone azgard says media');
});

it('goes red on a dead path, a dead tests entry and a suite without a zone', function (): void {
    $dead = z_broken(static function (array &$map): void {
        $map['zones']['media']['src'][] = 'packages/media/nowhere';
    });
    expect($dead['problems'])->toContain('missing path: packages/media/nowhere');

    $empty = z_broken(static function (array &$map): void {
        $map['zones']['core-kernel']['tests'][] = 'packages/core/tests/Fixtures';
    });
    expect($empty['problems'])->toContain('tests entry packages/core/tests/Fixtures of zone core-kernel holds no test file of suite core');

    $orphan = z_broken(static function (array &$map): void {
        unset($map['zones']['vue']);
        $map['zones']['vue-collaboration']['uses'] = [];
        $map['harness'] = array_values(array_filter($map['harness'], static fn (array $entry): bool => $entry['zones'] !== ['vue']));
    });
    expect($orphan['problems'])->toContain('suite vue is represented by no zone');
});

it('goes red on an unknown dependency and on a cycle', function (): void {
    $unknown = z_broken(static function (array &$map): void {
        $map['zones']['azgard']['uses'][] = 'ghost';
    });
    expect($unknown['problems'])->toContain('zone azgard uses unknown zone ghost');

    $cycle = z_broken(static function (array &$map): void {
        $map['zones']['core-kernel']['uses'] = ['media'];
    });
    expect(implode("\n", $cycle['problems']))->toContain('dependency cycle');
});

it('keeps the database classification and the prose allowlist well formed', function (): void {
    $map = z_json(z_root().'/tests/zones.json');

    expect($map['database'])->toBeArray();
    foreach ($map['database'] as $path) {
        expect(file_exists(z_root().'/'.$path))->toBeTrue("database entry $path does not exist");
    }
    foreach ($map['tail'] as $glob) {
        expect($glob)->not->toMatch('#(^|/)(AGENTS|CLAUDE)\.md$|openapi|resources|workflows#i');
    }
});
