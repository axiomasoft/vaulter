#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Routes a release tag and refuses before ANY publisher credential is read (fail closed).
 *
 *   route-release.php --tag=TAG --sha=SHA (--evidence=FILE | --evidence-dir=DIR) [--visibility=FILE] [--root=DIR] [--github-output=FILE]
 *
 * 1. the whole manifest is validated, 2. the tag selects exactly one release line, 3. the evidence of the SAME sha must
 * carry the exact artifact identity of each routed entry and, for the private channel, a probed private destination.
 * Without evidence nothing is qualified: there is no SHA-only fallback. Prints the routed set as JSON; exit 1 refuses.
 */

require __DIR__.'/manifest-lib.php';

$o = ['root' => dirname(__DIR__, 2)];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(tag|sha|evidence|evidence-dir|visibility|root|github-output)=(.*)$/', $arg, $m) !== 1) {
        fwrite(STDERR, "route-release: unknown argument {$arg}\n");
        exit(2);
    }
    $o[$m[1]] = $m[2];
}
$root = rtrim($o['root'], '/');

function refuse(string ...$reasons): never
{
    fwrite(STDERR, "route-release: REFUSED\n - ".implode("\n - ", $reasons)."\n");
    exit(1);
}

function readOptionalJson(?string $path): ?array
{
    if ($path === null || $path === '' || !is_file($path)) {
        return null;
    }
    try {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    return is_array($data) ? $data : null;
}

$manifest = readOptionalJson($root.'/release/packages.json') ?? refuse('release/packages.json is unreadable');
$errors = validateManifest($manifest);
if ($errors !== []) {
    refuse(...$errors);
}
if (!isset($o['tag'])) {
    refuse('--tag is required');
}
try {
    $route = routeTag($manifest, $o['tag']);
} catch (RuntimeException $e) {
    refuse($e->getMessage());
}

// A php package whose upstream is still a dev constraint is left out of the set (same rule as verify-split-tags.sh --excluded).
if ($route['kind'] === 'php') {
    $route['entries'] = array_values(array_filter($route['entries'], static function (array $entry) use ($root): bool {
        $upstream = $entry['requires_tag'] ?? null;
        if (!($entry['excluded_unless_tagged'] ?? false) || $upstream === null) {
            return true;
        }
        $composer = readOptionalJson($root.'/'.$entry['path'].'/composer.json') ?? refuse("{$entry['path']}/composer.json is unreadable");

        return !manifestIsDevConstraint((string) ($composer['require'][$upstream] ?? ''));
    }));
}

// --evidence-dir holds one evidence document per release line (<line>.json) and the probed visibility map.
$evidenceFile = $o['evidence'] ?? (isset($o['evidence-dir']) ? rtrim($o['evidence-dir'], '/').'/'.$route['line'].'.json' : null);
$visibilityFile = $o['visibility'] ?? (isset($o['evidence-dir']) ? rtrim($o['evidence-dir'], '/').'/visibility.json' : null);
$errors = qualificationErrors($route, readOptionalJson($evidenceFile), $o['sha'] ?? '', readOptionalJson($visibilityFile));
if ($errors !== []) {
    refuse(...$errors);
}

$set = array_map(static fn (array $e): string => $e['name'], $route['entries']);
$matrix = $route['kind'] === 'php' ? array_map(static fn (array $e): string => basename($e['path']), $route['entries']) : $set;
echo json_encode(['line' => $route['line'], 'kind' => $route['kind'], 'channel' => $route['channel'], 'version' => $route['version'], 'entries' => $set, 'matrix' => $matrix], JSON_UNESCAPED_SLASHES)."\n";
if (isset($o['github-output'])) {
    file_put_contents($o['github-output'], 'qualified=true'."\n".'channel='.$route['channel']."\n".'version='.$route['version']."\n".'matrix='.json_encode($matrix)."\n", FILE_APPEND);
}
