#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * scripts/test-select.php — which tests does a change need? (PLAN3 P1.4)
 *
 *   php scripts/test-select.php [--base=<ref>] [--quick] [--db=pgsql|mysql] [--json]
 *   php scripts/test-select.php --paths=a,b,c [--quick] [--db=pgsql|mysql] [--json]
 *   php scripts/test-select.php --check-map [--json]
 *   options for fixtures: --root=<repo> --map=<zones.json> --inventory=<suites.json>
 *
 * Input: `git diff <merge-base of --base and HEAD>..HEAD` (default base: main) plus staged, unstaged and untracked
 * files; a rename or a deletion counts for both paths. `--paths` replaces git with an explicit list.
 * The map is tests/zones.json — the only place that knows zones, their dependencies and the harness.
 *
 * What a path is, in this order: a harness file → the whole affected set; a prose file of the `tail` allowlist →
 * nothing to run; a src/tests entry of a leaf zone → that zone; anything else → unknown. Affected = the touched
 * zones plus every zone that `uses` them, transitively (`--quick`: only the touched ones). A zone marked `explicit`
 * (load) is never pulled in as a consumer.
 *
 * Exit: 0 a plan (possibly empty: no changes, prose only) · 2 refused — unknown path, harness change, a broken map,
 * a missing base, `--db` without a database classification. Exit 2 still prints the full conservative plan (every
 * affected zone and its consumers; unknown → every default zone) and the caller must not run a subset of it.
 * Zero tests is never a plan: an empty `runs` always carries a status that says why.
 *
 * Runs: core sub-zones are selected by the Pest group `zone:<name>` (tests/Pest.php), every other zone by its
 * suite; with `--db=<engine>` a run lists the files of the affected zones that are classified `database`.
 */

const EXIT_OK = 0;
const EXIT_REFUSED = 2;

/** @return array{0: int, 1: string, 2: string} */
function sh(array $command, ?string $cwd = null): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        return [127, '', 'cannot start '.$command[0]];
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $out, $err];
}

function normalize(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^(\./)+#', '', $path) ?? $path;

    return rtrim($path, '/');
}

function under(string $path, string $prefix): bool
{
    return $path === $prefix || str_starts_with($path, $prefix.'/');
}

function glob_regex(string $glob): string
{
    $regex = '';
    $length = strlen($glob);
    for ($i = 0; $i < $length; $i++) {
        $char = $glob[$i];
        if ($char === '*' && ($glob[$i + 1] ?? '') === '*') {
            $regex .= ($glob[$i + 2] ?? '') === '/' ? '(?:.*/)?' : '.*';
            $i += ($glob[$i + 2] ?? '') === '/' ? 2 : 1;
        } elseif ($char === '*') {
            $regex .= '[^/]*';
        } elseif ($char === '?') {
            $regex .= '[^/]';
        } else {
            $regex .= preg_quote($char, '#');
        }
    }

    return '#^'.$regex.'$#';
}

/** @return array<string, mixed> */
function read_json(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException("missing $file");
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        throw new RuntimeException("$file is not a JSON object");
    }

    return $data;
}

/** @return array<int, string> the leaf zones a zone name stands for (an aggregator → its children) */
function expand(array $map, string $name): array
{
    $zone = $map['zones'][$name] ?? null;
    if ($zone === null) {
        return [];
    }

    return isset($zone['children']) ? array_values($zone['children']) : [$name];
}

function is_string_list(mixed $value): bool
{
    return is_array($value) && array_is_list($value) && array_filter($value, static fn (mixed $v): bool => !is_string($v) || $v === '') === [];
}

/**
 * Structure and dependency graph of the map; no file-system access. Returns the problems (empty = valid).
 *
 * @param  array<string, mixed>      $map
 * @param  array<string, mixed>|null $inventory
 * @return array<int, string>
 */
function validate_map(array $map, ?array $inventory): array
{
    $errors = [];
    $zones = $map['zones'] ?? null;
    if (!is_array($zones) || $zones === []) {
        return ['zones must be a non-empty object'];
    }
    foreach (['harness', 'database', 'tail'] as $key) {
        if (!isset($map[$key]) || !is_array($map[$key])) {
            $errors[] = "$key must be an array";
        }
    }
    if (!is_string_list($map['tail'] ?? [])) {
        $errors[] = 'tail must be a list of glob strings';
    }
    if (!is_string_list($map['database'] ?? [])) {
        $errors[] = 'database must be a list of paths';
    }

    foreach ($zones as $name => $zone) {
        $name = (string) $name;
        if (!is_array($zone)) {
            $errors[] = "zone $name must be an object";

            continue;
        }
        if (isset($zone['children'])) {
            if (!is_string_list($zone['children']) || $zone['children'] === []) {
                $errors[] = "aggregator $name needs a non-empty children list";
            } else {
                foreach ($zone['children'] as $child) {
                    if (!isset($zones[$child])) {
                        $errors[] = "aggregator $name lists unknown child $child";
                    } elseif (($zones[$child]['parent'] ?? null) !== $name) {
                        $errors[] = "child $child of $name does not name it as parent";
                    } elseif (isset($zones[$child]['children'])) {
                        $errors[] = "child $child of $name is an aggregator itself";
                    }
                }
            }
            if (isset($zone['src']) || isset($zone['tests'])) {
                $errors[] = "aggregator $name must not own src/tests (leaf classification only)";
            }
        } else {
            foreach (['src', 'tests'] as $key) {
                if (!is_string_list($zone[$key] ?? null)) {
                    $errors[] = "zone $name: $key must be a list of paths";
                }
            }
            if (($zone['src'] ?? []) === []) {
                $errors[] = "zone $name: src must not be empty";
            }
            if (!is_string($zone['suite'] ?? null) || $zone['suite'] === '') {
                $errors[] = "zone $name: suite is required";
            } elseif ($inventory !== null && !isset($inventory['suites'][$zone['suite']])) {
                $errors[] = "zone $name: suite {$zone['suite']} is not in the suite inventory";
            }
            if (isset($zone['parent'])) {
                if (!isset($zones[$zone['parent']]['children']) || !in_array($name, $zones[$zone['parent']]['children'], true)) {
                    $errors[] = "zone $name: parent {$zone['parent']} does not list it as a child";
                }
            }
        }
        if (!is_string_list($zone['uses'] ?? null)) {
            $errors[] = "zone $name: uses must be a list of zone names";

            continue;
        }
        foreach ($zone['uses'] as $used) {
            if (!isset($zones[$used])) {
                $errors[] = "zone $name uses unknown zone $used";
            } elseif ($used === $name) {
                $errors[] = "zone $name uses itself";
            } elseif (($zone['parent'] ?? null) === $used) {
                $errors[] = "zone $name uses its own parent $used";
            }
        }
    }
    if ($errors !== []) {
        return $errors;
    }

    // leaf ownership: no two leaves share or nest a tests entry; no two leaves share a src entry
    $owners = ['tests' => [], 'src' => []];
    foreach ($zones as $name => $zone) {
        foreach (['tests', 'src'] as $kind) {
            foreach ($zone[$kind] ?? [] as $entry) {
                $owners[$kind][normalize($entry)][] = (string) $name;
            }
        }
    }
    foreach ($owners as $kind => $byEntry) {
        foreach ($byEntry as $entry => $names) {
            $names = array_values(array_unique($names));
            if (count($names) > 1) {
                $errors[] = "$kind entry $entry is owned by several zones: ".implode(', ', $names);
            }
        }
    }
    $testEntries = array_keys($owners['tests']);
    foreach ($testEntries as $a) {
        foreach ($testEntries as $b) {
            if ($a !== $b && under($b, (string) $a) && $owners['tests'][$a][0] !== $owners['tests'][$b][0]) {
                $errors[] = "tests entry $b is nested in $a of another zone";
            }
        }
    }

    foreach ($map['harness'] as $index => $entry) {
        if (!is_array($entry) || !is_string($entry['path'] ?? null) || !is_string_list($entry['zones'] ?? null) || $entry['zones'] === []) {
            $errors[] = "harness[$index] needs a path and a non-empty zones list";

            continue;
        }
        foreach ($entry['zones'] as $zone) {
            if (!isset($zones[$zone])) {
                $errors[] = "harness {$entry['path']} names unknown zone $zone";
            }
        }
    }

    // cycles over the expanded leaf graph
    $edges = [];
    foreach ($zones as $name => $zone) {
        if (isset($zone['children'])) {
            continue;
        }
        $edges[$name] = [];
        foreach ($zone['uses'] as $used) {
            $edges[$name] = array_merge($edges[$name], expand($map, $used));
        }
    }
    $state = [];
    $visit = static function (string $node, array $path) use (&$visit, &$state, $edges, &$errors): void {
        if (($state[$node] ?? 0) === 2) {
            return;
        }
        if (($state[$node] ?? 0) === 1) {
            $errors[] = 'dependency cycle: '.implode(' -> ', [...array_slice($path, (int) array_search($node, $path, true)), $node]);

            return;
        }
        $state[$node] = 1;
        foreach ($edges[$node] ?? [] as $next) {
            $visit($next, [...$path, $node]);
        }
        $state[$node] = 2;
    };
    foreach (array_keys($edges) as $node) {
        $visit((string) $node, []);
    }

    return array_values(array_unique($errors));
}

/** Everything that must exist on disk for the map to be true. @return array<int, string> */
function missing_paths(array $map, string $root): array
{
    $missing = [];
    $check = static function (string $path) use ($root, &$missing): void {
        if (!file_exists($root.'/'.$path)) {
            $missing[] = $path;
        }
    };
    foreach ($map['zones'] as $zone) {
        foreach (array_merge($zone['src'] ?? [], $zone['tests'] ?? []) as $path) {
            $check(normalize($path));
        }
    }
    foreach ($map['harness'] as $entry) {
        $check(normalize($entry['path']));
    }
    foreach ($map['database'] as $path) {
        $check(normalize($path));
    }

    return array_values(array_unique($missing));
}

/**
 * @return array{0: array<string, array{path: string, status: string, from?: string}>, 1: array<int, string>, 2: ?string}
 *                                                                                                                        changed entries keyed by path, errors, merge-base
 */
function git_changes(string $root, string $base): array
{
    [$code, $out] = sh(['git', 'rev-parse', '--is-inside-work-tree'], $root);
    if ($code !== 0 || trim($out) !== 'true') {
        return [[], ["$root is not a git work tree; pass --paths"], null];
    }
    [$code, $head] = sh(['git', 'rev-parse', '--verify', '--quiet', 'HEAD^{commit}'], $root);
    if ($code !== 0) {
        return [[], ['HEAD does not resolve to a commit'], null];
    }
    [$code, $baseSha] = sh(['git', 'rev-parse', '--verify', '--quiet', $base.'^{commit}'], $root);
    if ($code !== 0) {
        return [[], ["base [$base] does not resolve; in a shallow checkout fetch exactly that commit: git fetch --depth=1 origin $base"], null];
    }
    [$code, $mergeBase] = sh(['git', 'merge-base', trim($baseSha), trim($head)], $root);
    if ($code !== 0 || trim($mergeBase) === '') {
        return [[], ["no merge base between [$base] and HEAD (shallow history?): git fetch --deepen=<n> origin $base"], null];
    }
    $mergeBase = trim($mergeBase);

    $changed = [];
    $record = static function (string $path, string $status, ?string $from = null) use (&$changed): void {
        $path = normalize($path);
        if ($path === '') {
            return;
        }
        $entry = ['path' => $path, 'status' => $status];
        if ($from !== null) {
            $entry['from'] = normalize($from);
        }
        $changed[$path] = isset($changed[$path]) ? ['path' => $path, 'status' => $changed[$path]['status'].'+'.$status] + $entry : $entry;
    };

    [$code, $out, $err] = sh(['git', 'diff', '--name-status', '-z', '-M', $mergeBase, 'HEAD'], $root);
    if ($code !== 0) {
        return [[], ['git diff failed: '.trim($err)], $mergeBase];
    }
    $tokens = explode("\0", rtrim($out, "\0"));
    for ($i = 0; $i < count($tokens) && $tokens[$i] !== ''; $i++) {
        $status = $tokens[$i];
        if ($status[0] === 'R' || $status[0] === 'C') {
            $record($tokens[$i + 2], $status[0], $tokens[$i + 1]);
            $record($tokens[$i + 1], 'D');
            $i += 2;
        } else {
            $record($tokens[++$i], $status[0]);
        }
    }

    [$code, $out, $err] = sh(['git', 'status', '--porcelain=v1', '-z', '--untracked-files=all', '--ignore-submodules=none'], $root);
    if ($code !== 0) {
        return [[], ['git status failed: '.trim($err)], $mergeBase];
    }
    $tokens = explode("\0", rtrim($out, "\0"));
    for ($i = 0; $i < count($tokens) && $tokens[$i] !== ''; $i++) {
        $xy = substr($tokens[$i], 0, 2);
        $path = substr($tokens[$i], 3);
        if ($xy[0] === 'R' || $xy[0] === 'C' || $xy[1] === 'R' || $xy[1] === 'C') {
            $from = $tokens[++$i];
            $record($path, 'R', $from);
            $record($from, 'D');
        } else {
            $record($path, $xy === '??' ? 'A' : trim($xy)[0]);
        }
    }

    ksort($changed);

    return [$changed, [], $mergeBase];
}

/**
 * @param  array<string, mixed> $map
 * @param  array<int, string>   $paths
 * @return array<string, mixed> classification
 */
function classify(array $map, array $paths): array
{
    $entries = [];
    foreach ($map['zones'] as $name => $zone) {
        foreach (array_merge($zone['src'] ?? [], $zone['tests'] ?? []) as $entry) {
            $entries[] = [normalize($entry), (string) $name];
        }
    }
    usort($entries, static fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));
    $tail = array_map('glob_regex', $map['tail']);

    $result = ['zones' => [], 'harness' => [], 'docs' => [], 'unknown' => []];
    foreach ($paths as $path) {
        $hits = [];
        foreach ($map['harness'] as $entry) {
            if (under($path, normalize($entry['path']))) {
                $hits = array_merge($hits, $entry['zones']);
            }
        }
        if ($hits !== []) {
            $result['harness'][$path] = array_values(array_unique($hits));

            continue;
        }
        foreach ($tail as $regex) {
            if (preg_match($regex, $path) === 1) {
                $result['docs'][] = $path;

                continue 2;
            }
        }
        foreach ($entries as [$entry, $zone]) {
            if (under($path, $entry)) {
                $result['zones'][$path] = $zone;

                continue 2;
            }
        }
        $result['unknown'][] = $path;
    }

    return $result;
}

/** Zones that use $leaf directly. @return array<string, array<int, string>> */
function dependents(array $map): array
{
    $result = [];
    foreach ($map['zones'] as $name => $zone) {
        if (isset($zone['children'])) {
            continue;
        }
        foreach ($zone['uses'] as $used) {
            foreach (expand($map, $used) as $leaf) {
                $result[$leaf][] = (string) $name;
            }
        }
    }

    return $result;
}

/**
 * @param  array<int, string> $seeds
 * @return array<int, string> seeds plus everything that (transitively) uses them, `explicit` zones excluded
 */
function close_over(array $map, array $seeds): array
{
    $dependents = dependents($map);
    $seen = array_fill_keys($seeds, true);
    $queue = $seeds;
    while ($queue !== []) {
        $node = array_shift($queue);
        foreach ($dependents[$node] ?? [] as $next) {
            if (isset($seen[$next]) || ($map['zones'][$next]['explicit'] ?? false) === true) {
                continue;
            }
            $seen[$next] = true;
            $queue[] = $next;
        }
    }

    return array_keys($seen);
}

/** @return array<int, string> every leaf zone in map order */
function leaf_zones(array $map): array
{
    return array_values(array_filter(array_keys($map['zones']), static fn (string|int $name): bool => !isset($map['zones'][$name]['children'])));
}

function shell_word(string $word): string
{
    return preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $word) === 1 ? $word : escapeshellarg($word);
}

/**
 * @param  array<string, mixed>                                                                     $map
 * @param  array<int, string>                                                                       $affected  leaf zones
 * @param  array<string, mixed>|null                                                                $inventory
 * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>, 2: array<int, string>} runs, notes, errors
 */
function build_runs(array $map, array $affected, ?string $db, ?array $inventory): array
{
    $bySuite = [];
    foreach (leaf_zones($map) as $leaf) {
        if (in_array($leaf, $affected, true)) {
            $bySuite[$map['zones'][$leaf]['suite']][] = $leaf;
        }
    }
    $runs = [];
    $notes = [];
    $errors = [];

    if ($db !== null) {
        if ($map['database'] === []) {
            return [[], [], ['--db needs the database classification (tests/zones.json "database"), which is empty; it is filled by P1.5']];
        }
        if ($inventory === null) {
            return [[], [], ['--db needs the suite inventory (tests/suites.json)']];
        }
    }

    foreach ($bySuite as $suite => $leaves) {
        $suite = (string) $suite;
        $kind = $inventory['suites'][$suite]['kind'] ?? ($suite === 'hub' || $suite === 'vue' ? 'node' : 'pest');
        $siblings = array_filter(leaf_zones($map), static fn (string $l): bool => $map['zones'][$l]['suite'] === $suite);
        $grouped = count($siblings) > 1 && count($leaves) < count($siblings);
        $groups = $grouped ? array_map(static fn (string $leaf): string => 'zone:'.$leaf, $leaves) : [];

        if ($db === null) {
            $args = $kind === 'node' ? ['-p', $suite] : ['sqlite', '-p', $suite];
            $groups === [] || $args[] = '--group='.implode(',', $groups);
            $runs[] = ['suite' => $suite, 'engine' => $kind === 'node' ? '-' : 'sqlite', 'leaves' => $leaves, 'groups' => $groups, 'paths' => [], 'args' => $args];

            continue;
        }

        if ($kind === 'node') {
            $notes[] = "$suite: a Node suite has no database classification";

            continue;
        }
        $zoneTests = [];
        foreach ($leaves as $leaf) {
            $zoneTests = array_merge($zoneTests, array_map('normalize', $map['zones'][$leaf]['tests']));
        }
        $files = [];
        foreach ($inventory['suites'][$suite]['partitions'] ?? [] as $partition) {
            foreach ($partition['files'] as $file) {
                $inZone = array_filter($zoneTests, static fn (string $entry): bool => under($file, $entry)) !== [];
                $isDatabase = array_filter($map['database'], static fn (string $entry): bool => under($file, normalize($entry))) !== [];
                if ($inZone && $isDatabase) {
                    $files[] = $file;
                }
            }
        }
        sort($files);
        if ($files === []) {
            $notes[] = "$suite: no database-classified test in the affected zones (".implode(', ', $leaves).')';

            continue;
        }
        $runs[] = ['suite' => $suite, 'engine' => $db, 'leaves' => $leaves, 'groups' => [], 'paths' => $files, 'args' => [$db, '-p', $suite]];
    }

    foreach ($runs as &$run) {
        $run['command'] = 'scripts/t '.implode(' ', array_map('shell_word', $run['args'])).($run['paths'] === [] ? '' : ' -- '.implode(' ', array_map('shell_word', $run['paths'])));
    }
    unset($run);

    return [$runs, $notes, $errors];
}

function option(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--$name=")) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return $default;
}

function flag(array $argv, string $name): bool
{
    return in_array("--$name", $argv, true);
}

function usage(): string
{
    $lines = file(__FILE__, FILE_IGNORE_NEW_LINES);
    $out = [];
    foreach (array_slice($lines, 3) as $line) {
        if (str_starts_with($line, ' */')) {
            break;
        }
        $out[] = preg_replace('#^ ?\*? ?#', '', $line);
    }

    return implode("\n", array_slice($out, 1))."\n";
}

function finish(array $result, bool $json): never
{
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
        exit($result['exit']);
    }

    $line = static fn (string $label, array $items): string => $items === [] ? '' : sprintf("%-10s %s\n", $label.':', implode(', ', $items));
    echo "status:    {$result['status']} (exit {$result['exit']})", isset($result['reasons']) && count($result['reasons']) > 1 ? ' — '.implode(' + ', $result['reasons']) : '', "\n";
    if (isset($result['base'])) {
        echo "base:      {$result['base']}".(isset($result['merge_base']) ? ' (merge-base '.substr((string) $result['merge_base'], 0, 10).')' : '')."\n";
    }
    echo $line('errors', $result['errors'] ?? []);
    echo $line('changed', array_map(static fn (array $c): string => $c['path'].' ['.$c['status'].']', $result['changed'] ?? []));
    echo $line('harness', array_keys($result['classified']['harness'] ?? []));
    echo $line('unknown', $result['classified']['unknown'] ?? []);
    echo $line('docs', $result['classified']['docs'] ?? []);
    echo $line('touched', $result['touched'] ?? []);
    echo $line('consumers', $result['consumers'] ?? []);
    echo $line('notes', $result['notes'] ?? []);
    foreach ($result['runs'] ?? [] as $run) {
        echo "run:       {$run['command']}\n";
    }
    if (($result['exit'] ?? 0) === EXIT_REFUSED && ($result['runs'] ?? []) !== []) {
        echo "refused:   the plan above is the full affected set; run it deliberately (qualification), never a subset\n";
    }
    exit($result['exit']);
}

function main(array $argv): never
{
    $json = flag($argv, 'json');
    if (flag($argv, 'help') || flag($argv, '-h') || in_array('-h', $argv, true)) {
        echo usage();
        exit(EXIT_OK);
    }

    $root = rtrim(option($argv, 'root', dirname(__DIR__)) ?? '', '/');
    $mapFile = option($argv, 'map', $root.'/tests/zones.json');
    $inventoryFile = option($argv, 'inventory', $root.'/tests/suites.json');
    $base = option($argv, 'base', 'main') ?? 'main';
    $quick = flag($argv, 'quick');
    $db = option($argv, 'db');
    $result = ['status' => 'error', 'exit' => EXIT_REFUSED, 'errors' => []];

    $known = ['json', 'quick', 'check-map', 'help'];
    foreach (array_slice($argv, 1) as $arg) {
        $name = ltrim(explode('=', $arg, 2)[0], '-');
        if (!str_starts_with($arg, '--') || !in_array($name, [...$known, 'root', 'map', 'inventory', 'base', 'paths', 'db'], true)) {
            $result['errors'][] = "unknown argument [$arg]";
            finish($result, $json);
        }
    }
    if ($db !== null && !in_array($db, ['pgsql', 'mysql'], true)) {
        $result['errors'][] = "--db must be pgsql or mysql, got [$db]";
        finish($result, $json);
    }

    try {
        $map = read_json($mapFile);
        $inventory = is_file($inventoryFile) ? read_json($inventoryFile) : null;
    } catch (Throwable $e) {
        $result['status'] = 'invalid_map';
        $result['errors'][] = $e->getMessage();
        finish($result, $json);
    }

    $problems = validate_map($map, $inventory);
    if (flag($argv, 'check-map')) {
        $problems = $problems === [] ? array_map(static fn (string $p): string => "missing path: $p", missing_paths($map, $root)) : $problems;
        finish(['status' => $problems === [] ? 'ok' : 'invalid_map', 'exit' => $problems === [] ? EXIT_OK : EXIT_REFUSED, 'errors' => $problems], $json);
    }
    if ($problems !== []) {
        finish(['status' => 'invalid_map', 'exit' => EXIT_REFUSED, 'errors' => $problems, 'runs' => []], $json);
    }

    $mergeBase = null;
    if (option($argv, 'paths') !== null) {
        $changed = [];
        foreach (array_filter(explode(',', (string) option($argv, 'paths'))) as $path) {
            $changed[normalize($path)] = ['path' => normalize($path), 'status' => 'M'];
        }
        $base = null;
    } else {
        [$changed, $errors, $mergeBase] = git_changes($root, $base);
        if ($errors !== []) {
            finish(['status' => 'error', 'exit' => EXIT_REFUSED, 'base' => $base, 'errors' => $errors, 'runs' => []], $json);
        }
    }

    $paths = [];
    foreach ($changed as $entry) {
        $paths[$entry['path']] = true;
        if (isset($entry['from'])) {
            $paths[$entry['from']] = true;
        }
    }
    $paths = array_keys($paths);
    sort($paths);

    $classified = classify($map, $paths);
    $touched = array_values(array_unique(array_values($classified['zones'])));
    $harnessLeaves = [];
    foreach ($classified['harness'] as $zones) {
        foreach ($zones as $zone) {
            $harnessLeaves = array_merge($harnessLeaves, expand($map, $zone));
        }
    }
    $touched = array_values(array_unique(array_merge($touched, $harnessLeaves)));

    $reasons = [];
    $classified['unknown'] === [] || $reasons[] = 'unknown';
    $classified['harness'] === [] || $reasons[] = 'harness';
    $refused = $reasons !== [];

    // exit 2 always plans the whole affected set; a clean change honours --quick
    $affected = $quick && !$refused ? $touched : close_over($map, $touched);
    if ($classified['unknown'] !== []) {
        $affected = array_values(array_unique(array_merge(
            $affected,
            array_filter(leaf_zones($map), static fn (string $leaf): bool => ($map['zones'][$leaf]['explicit'] ?? false) !== true),
        )));
    }
    $consumers = array_values(array_diff($affected, $touched));
    $order = leaf_zones($map);
    usort($affected, static fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

    $result = [
        'status' => 'ok',
        'exit' => EXIT_OK,
        'base' => $base,
        'merge_base' => $mergeBase,
        'quick' => $quick,
        'db' => $db,
        'changed' => array_values($changed),
        'classified' => $classified,
        'touched' => $touched,
        'consumers' => $consumers,
        'affected' => $affected,
        'notes' => [],
        'errors' => [],
        'runs' => [],
    ];

    if ($changed === []) {
        $result['status'] = 'no_changes';
        $result['notes'][] = 'no changed path: nothing to run (not a pass of any test)';
        finish($result, $json);
    }
    if (!$refused && $touched === []) {
        $result['status'] = 'docs_only';
        $result['notes'][] = 'only allowlisted prose changed: no test applies';
        finish($result, $json);
    }

    [$runs, $notes, $errors] = build_runs($map, $affected, $db, $inventory);
    $result['runs'] = $runs;
    $result['notes'] = array_merge($result['notes'], $notes);
    $result['errors'] = $errors;
    if ($errors !== []) {
        $result['status'] = 'invalid_request';
        $result['exit'] = EXIT_REFUSED;
    } elseif ($refused) {
        $result['status'] = $reasons[0];
        $result['reasons'] = $reasons;
        $result['exit'] = EXIT_REFUSED;
        $result['notes'][] = $classified['unknown'] !== []
            ? 'unknown path: the plan is every default zone — run the full set deliberately'
            : 'harness change: the plan is every affected zone and its consumers — run the full set deliberately';
    } elseif ($runs === []) {
        $result['status'] = 'no_database_tests';
        $result['notes'][] = 'the classification proves no engine-dependent test is affected';
    }
    finish($result, $json);
}

main($argv);
