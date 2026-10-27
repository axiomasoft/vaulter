#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Release invariants of the monorepo, read from release/packages.json (schema v2):
 * every entry is validated (channel, release line, tag prefix, uniqueness) BEFORE any filtering, an unknown or
 * missing channel fails closed; public siblings are required only as self.version, private packages use their
 * tested ranges and no public package may reach a private one; nothing shipped requires a dev
 * constraint, the split matrix equals the manifest and the npm client declares
 * the REST API line it speaks.
 *
 * Usage: check-versions.php [--root=DIR]
 */

require __DIR__.'/manifest-lib.php';

$root = dirname(__DIR__, 2);
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = rtrim(substr($arg, 7), '/');
    }
}

function readJson(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("cannot read {$path}");
    }

    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

function isDevConstraint(string $constraint): bool
{
    return manifestIsDevConstraint($constraint);
}

$errors = [];

try {
    $manifest = readJson($root.'/release/packages.json');
} catch (Throwable $e) {
    fwrite(STDERR, "check-versions: {$e->getMessage()}\n");
    exit(1);
}

if (($manifest['schema'] ?? null) !== 'vaulter.release/v2') {
    fwrite(STDERR, "check-versions: release/packages.json schema must be \"vaulter.release/v2\"\n");
    exit(1);
}

// Validate ALL entries of ALL kinds before anything is filtered by channel (shared with route-release/build-artifacts).
$errors = validateManifest($manifest);
if ($errors !== []) {
    fwrite(STDERR, 'check-versions: '.count($errors)." manifest violation(s)\n - ".implode("\n - ", $errors)."\n");
    exit(1);
}

$names = array_column($manifest['php'] ?? [], 'name');
$channelOf = [];
foreach ($manifest['php'] as $package) {
    $channelOf[$package['name']] = $package['channel'];
}

foreach ($manifest['php'] ?? [] as $package) {
    $file = $root.'/'.$package['path'].'/composer.json';
    try {
        $composer = readJson($file);
    } catch (Throwable $e) {
        $errors[] = "{$package['name']}: {$e->getMessage()}";

        continue;
    }

    if (($composer['name'] ?? null) !== $package['name']) {
        $errors[] = "{$package['name']}: composer.json declares name ".var_export($composer['name'] ?? null, true);
    }
    $isPrivate = $package['channel'] === 'private';
    if ($isPrivate && ($composer['license'] ?? null) !== ($package['license'] ?? null)) {
        $errors[] = "{$package['name']}: composer license ".var_export($composer['license'] ?? null, true).' differs from manifest license '.var_export($package['license'] ?? null, true);
    }
    if (!$isPrivate && ($composer['extra']['branch-alias']['dev-main'] ?? '1.0.x-dev') !== '1.0.x-dev') {
        $errors[] = "{$package['name']}: branch-alias dev-main must be 1.0.x-dev";
    }

    // Same rule as verify-split-tags.sh --excluded: left out only while the upstream is required as a dev constraint.
    $upstream = $package['requires_tag'] ?? null;
    $excludedUntilTagged = ($package['excluded_unless_tagged'] ?? false)
        && $upstream !== null
        && isDevConstraint((string) ($composer['require'][$upstream] ?? ''));

    foreach (['require', 'require-dev', 'suggest'] as $section) {
        foreach ($composer[$section] ?? [] as $dependency => $constraint) {
            $target = $channelOf[$dependency] ?? null;
            if ($target === 'private' && !$isPrivate) {
                $errors[] = "{$package['name']}: public package references private {$dependency} in {$section}";

                continue;
            }
            if ($section === 'suggest') {
                continue;
            }
            if ($target === 'public' && $isPrivate) {
                $range = $package['tested_ranges'][$dependency] ?? null;
                if ($range === null || $constraint !== $range || preg_match('/^\^\d+\.\d+$/', $constraint) !== 1) {
                    $errors[] = "{$package['name']}: {$section} {$dependency} is \"{$constraint}\", expected tested range ".var_export($range, true);
                }
            } elseif ($target !== null && $constraint !== 'self.version') {
                $errors[] = "{$package['name']}: {$section} {$dependency} is \"{$constraint}\", expected \"self.version\"";
            }

            if (isDevConstraint($constraint)) {
                if ($excludedUntilTagged && $dependency === $upstream) {
                    continue;
                }
                $errors[] = "{$package['name']}: {$section} {$dependency} uses dev constraint \"{$constraint}\"";
            }
        }
    }
}

// The split matrix is computed by route-release.php from the manifest, so the workflow must consume it and carry no
// second static list; every php entry must route from the tag its own prefix would create.
$workflow = @file_get_contents($root.'/.github/workflows/monorepo-split.yml');
if ($workflow === false) {
    $errors[] = 'monorepo-split.yml is missing';
} else {
    if (!str_contains($workflow, 'fromJSON(needs.route.outputs.matrix)')) {
        $errors[] = 'monorepo-split.yml: split matrix must be fromJSON(needs.route.outputs.matrix)';
    }
    if (preg_match('/^\s*package:\s*\n\s*-\s*\S+\s*$/m', $workflow) === 1) {
        $errors[] = 'monorepo-split.yml: static package matrix list found next to the dynamic matrix';
    }
}
foreach (RELEASE_KINDS as $kind) {
    foreach ($manifest[$kind] as $entry) {
        try {
            $routed = routeTag($manifest, $entry['tag_prefix'].'1.0.0');
            if ($routed['line'] !== $entry['release_line'] || !in_array($entry['name'], array_column($routed['entries'], 'name'), true)) {
                $errors[] = "{$entry['name']}: tag {$entry['tag_prefix']}1.0.0 routes to {$routed['line']}";
            }
        } catch (RuntimeException $e) {
            $errors[] = "{$entry['name']}: {$e->getMessage()}";
        }
    }
}

// D17: the declared wire/state tuples equal what the runtime code announces, accepts and produces.
$declared = $manifest['compatibility']['collaboration'] ?? null;
$runtime = runtimeWireState($root);
if (!is_array($declared)) {
    $errors[] = 'manifest compatibility.collaboration (produced/accepted wire+state tuples) is missing';
} else {
    $sorted = static function (array $rows): array {
        usort($rows, static fn (array $x, array $y): int => strcmp(json_encode($x), json_encode($y)));

        return $rows;
    };
    foreach (['protocol', 'version'] as $field) {
        if (($declared[$field] ?? null) !== $runtime[$field] || $runtime[$field] === '') {
            $errors[] = "compatibility.collaboration.{$field} is ".var_export($declared[$field] ?? null, true).', runtime says '.var_export($runtime[$field], true);
        }
    }
    foreach (['accepted', 'produced'] as $field) {
        if ($runtime[$field] === [] || $sorted($declared[$field] ?? []) !== $runtime[$field]) {
            $errors[] = "compatibility.collaboration.{$field} differs from runtime ".json_encode($runtime[$field]);
        }
    }
    foreach ($runtime['produced'] as $tuple) {
        if (!in_array($tuple, $runtime['accepted'], true)) {
            $errors[] = 'runtime produces a state tuple its driver does not accept: '.json_encode($tuple);
        }
    }
}

// Private floors must be satisfiable by the ACTUAL version the public line carries (branch-alias / package version).
$satisfies = static fn (string $range, string $version): bool => preg_match('/^\^(\d+)\.(\d+)$/', $range, $r) === 1
    && preg_match('/^(\d+)\.(\d+)/', $version, $v) === 1 && (int) $v[1] === (int) $r[1] && (int) $v[2] >= (int) $r[2];
foreach ($manifest['php'] as $package) {
    foreach ($package['tested_ranges'] ?? [] as $dependency => $range) {
        $target = array_values(array_filter($manifest['php'], static fn (array $p): bool => $p['name'] === $dependency))[0] ?? null;
        $alias = $target === null ? null : (readJson($root.'/'.$target['path'].'/composer.json')['extra']['branch-alias']['dev-main'] ?? null);
        if ($target === null || !is_string($alias) || !$satisfies($range, $alias)) {
            $errors[] = "{$package['name']}: tested range {$range} of {$dependency} is not satisfied by its branch-alias ".var_export($alias, true);
        }
    }
}

$changeset = readJson($root.'/.changeset/config.json');
if (($changeset['access'] ?? null) !== 'restricted') {
    $errors[] = '.changeset/config.json: default access must be "restricted" (public is opted in per package via publishConfig)';
}

foreach ($manifest['npm'] ?? [] as $package) {
    try {
        $json = readJson($root.'/'.$package['path'].'/package.json');
    } catch (Throwable $e) {
        $errors[] = "{$package['name']}: {$e->getMessage()}";

        continue;
    }

    foreach ($package['tested_ranges'] ?? [] as $dependency => $range) {
        $dependencyEntry = array_values(array_filter($manifest['npm'], static fn (array $p): bool => $p['name'] === $dependency))[0] ?? null;
        $actual = $dependencyEntry === null ? null : (readJson($root.'/'.$dependencyEntry['path'].'/package.json')['version'] ?? null);
        if (!is_string($actual) || !$satisfies($range, $actual)) {
            $errors[] = "{$package['name']}: floor {$range} of {$dependency} is not satisfied by the workspace version ".var_export($actual, true);
        }
        foreach (['peerDependencies', 'devDependencies'] as $section) {
            if (isset($json[$section][$dependency]) && $json[$section][$dependency] !== $range) {
                $errors[] = "{$package['name']}: {$section} {$dependency} is \"{$json[$section][$dependency]}\", expected tested floor \"{$range}\"";
            }
        }
    }
    if (($json['publishConfig']['access'] ?? null) !== $package['access']) {
        $errors[] = "{$package['name']}: publishConfig.access must be \"{$package['access']}\"";
    }
    if (($json['vaulter']['api'] ?? null) !== $package['api']) {
        $errors[] = "{$package['name']}: package.json must declare \"vaulter\": {\"api\": \"{$package['api']}\"}";
    }
}

if ($errors !== []) {
    fwrite(STDERR, 'check-versions: '.count($errors)." violation(s)\n - ".implode("\n - ", $errors)."\n");
    exit(1);
}

echo 'check-versions: OK ('.count($names)." PHP packages)\n";
