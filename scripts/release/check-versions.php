#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Release invariants of the monorepo, read from release/packages.json:
 * siblings are required only as self.version, nothing shipped requires a dev
 * constraint, the split matrix equals the manifest and the npm client declares
 * the REST API line it speaks.
 *
 * Usage: check-versions.php [--root=DIR]
 */

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
    return str_starts_with($constraint, 'dev-') || str_contains($constraint, '@dev') || str_ends_with($constraint, '-dev');
}

$errors = [];

try {
    $manifest = readJson($root.'/release/packages.json');
} catch (Throwable $e) {
    fwrite(STDERR, "check-versions: {$e->getMessage()}\n");
    exit(1);
}

$names = array_column($manifest['php'] ?? [], 'name');

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

    // Same rule as verify-split-tags.sh --excluded: left out only while the upstream is required as a dev constraint.
    $upstream = $package['requires_tag'] ?? null;
    $excludedUntilTagged = ($package['excluded_unless_tagged'] ?? false)
        && $upstream !== null
        && isDevConstraint((string) ($composer['require'][$upstream] ?? ''));

    foreach (['require', 'require-dev'] as $section) {
        foreach ($composer[$section] ?? [] as $dependency => $constraint) {
            if (in_array($dependency, $names, true) && $constraint !== 'self.version') {
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

$workflowFile = $root.'/.github/workflows/monorepo-split.yml';
$workflow = @file_get_contents($workflowFile);
if ($workflow === false) {
    $errors[] = 'monorepo-split.yml is missing';
} elseif (preg_match('/^\s*package:\s*\n((?:\s*-\s*\S+\s*\n)+)/m', $workflow, $matrix) !== 1) {
    $errors[] = 'monorepo-split.yml: split matrix package list not found';
} else {
    preg_match_all('/-\s*(\S+)/', $matrix[1], $cells);
    $expected = array_map(static fn (array $p): string => basename($p['path']), $manifest['php'] ?? []);
    sort($expected);
    $actual = $cells[1];
    sort($actual);
    if ($expected !== $actual) {
        $errors[] = 'monorepo-split.yml matrix ['.implode(', ', $actual).'] differs from manifest ['.implode(', ', $expected).']';
    }
}

foreach ($manifest['npm'] ?? [] as $package) {
    try {
        $json = readJson($root.'/'.$package['path'].'/package.json');
    } catch (Throwable $e) {
        $errors[] = "{$package['name']}: {$e->getMessage()}";

        continue;
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
