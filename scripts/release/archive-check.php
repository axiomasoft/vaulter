#!/usr/bin/env php
<?php

declare(strict_types=1);

// Candidate PHP is data only. This file never loads a package autoloader or executes package scripts.
require_once __DIR__.'/manifest-lib.php';

function archiveSafePath(string $path): bool
{
    return $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '\\')
        && preg_match('~(^|/)(\.{1,2}|)(/|$)|[\x00-\x1f\x7f]~', $path) !== 1;
}

/** Inspect ZIP members BEFORE extraction; no extraction is needed. */
function archiveContentErrors(string $path, array $package, array $manifest): array
{
    $zip = new ZipArchive;
    if ($zip->open($path) !== true) {
        return ['unreadable ZIP'];
    }
    $errors = [];
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $opsys = $attrs = 0;
        $zip->getExternalAttributesIndex($i, $opsys, $attrs);
        if (!archiveSafePath($name) || (($attrs >> 16) & 0170000) === 0120000) {
            $errors[] = "unsafe path or symlink: {$name}";

            continue;
        }
        if (array_key_exists($name, $files)) {
            $errors[] = "duplicate member: {$name}";
        }
        $files[$name] = (string) $zip->getFromIndex($i);
        $parts = explode('/', $name);
        $denied = array_intersect($parts, ['tests', 'vendor', 'node_modules', '.git', '.env', 'hub', 'phpunit.xml', 'pint.json', 'rector.php', 'composer.lock']);
        $rootAllowed = preg_match('/^(composer\.json|LICENSE[^\/]*|README\.md|CHANGELOG\.md|UPGRADE\.md|SECURITY\.md)$/', $name) === 1;
        $dirAllowed = count($parts) > 1 && in_array($parts[0], ['src', 'config', 'database', 'routes', 'resources', 'lang', 'stubs', 'bin'], true);
        $privateDocs = ($package['channel'] ?? '') === 'private' && ($package['path'] ?? '') === 'packages/collaboration' && count($parts) > 1 && $parts[0] === 'docs';
        if (array_filter($parts, static fn (string $p): bool => str_starts_with($p, '.env.')) !== [] || $denied !== [] || str_ends_with($name, '.cache') || (!$rootAllowed && !$dirAllowed && !$privateDocs)) {
            $errors[] = "forbidden member: {$name}";
        }
    }
    $zip->close();
    try {
        $composer = json_decode($files['composer.json'] ?? '', true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [...$errors, 'invalid or missing composer.json'];
    }
    if (!is_array($composer)) {
        return [...$errors, 'composer.json must be an object'];
    }
    if (($composer['name'] ?? null) !== $package['name']) {
        $errors[] = 'composer name differs from manifest';
    }
    $license = $package['channel'] === 'private' ? 'proprietary' : 'MIT';
    if (($composer['license'] ?? null) !== $license) {
        $errors[] = "license must be {$license}";
    }
    if ($package['channel'] === 'public') {
        foreach ($manifest['php'] as $entry) {
            if ($entry['channel'] === 'private' && isset($composer['require'][$entry['name']])) {
                $errors[] = 'public package requires private '.$entry['name'];
            }
        }
    }
    $required = ['composer.json'];
    foreach ($composer['autoload']['files'] ?? [] as $file) {
        $required[] = $file;
    }
    foreach ((array) ($composer['bin'] ?? []) as $file) {
        $required[] = $file;
    }
    if (!($package['metapackage'] ?? false)) {
        foreach ($composer['extra']['laravel']['providers'] ?? [] as $provider) {
            $found = false;
            foreach ($composer['autoload']['psr-4'] ?? [] as $prefix => $dirs) {
                if (str_starts_with($provider, $prefix)) {
                    foreach ((array) $dirs as $dir) {
                        $required[] = rtrim($dir, '/').'/'.str_replace('\\', '/', substr($provider, strlen($prefix))).'.php';
                        $found = true;
                    }
                }
            }
            if (!$found) {
                $errors[] = 'provider has no PSR-4 mapping';
            }
        }
        $short = basename($package['path']);
        $required[] = 'src/Vaulter'.($short === 'core' ? '' : ucfirst($short)).'ServiceProvider.php';
        $required[] = 'config/'.($short === 'core' ? 'vaulter' : 'vaulter-'.$short).'.php';
        if ($short === 'core') {
            $required[] = 'resources/schemas/doctor-v1.schema.json';
        }
        if (in_array($short, ['drive', 'documents', 'webhooks', 'collaboration'], true)) {
            $required[] = 'routes/'.$short.'.php';
        }
        if (in_array($short, ['core', 'media', 'drive', 'documents', 'webhooks', 'collaboration'], true)) {
            $migrations = array_filter(array_keys($files), static fn (string $f): bool => str_starts_with($f, 'database/migrations/') && str_ends_with($f, '.php') && $files[$f] !== '');
            if ($migrations === []) {
                $errors[] = 'missing database migrations';
            }
        }
        if (!array_filter(array_keys($files), static fn (string $f): bool => str_starts_with($f, 'src/') && str_ends_with($f, '.php') && $files[$f] !== '')) {
            $errors[] = 'missing src PHP';
        }
    } elseif (($composer['type'] ?? null) !== 'metapackage') {
        $errors[] = 'metapackage type required';
    }
    foreach ($required as $file) {
        if (!archiveSafePath($file) || ($files[$file] ?? '') === '') {
            $errors[] = "missing or empty required file: {$file}";
        }
    }

    return $errors;
}

function archiveCommand(array $args, ?string $cwd = null, ?string $input = null): string
{
    $process = proc_open($args, [0 => ['file', $input ?? '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes, $cwd);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('command failed: '.implode(' ', $args));
    }

    return (string) $out;
}

function archiveRemove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

function archiveMain(array $args): int
{
    $root = dirname(__DIR__, 2);
    $ref = null;
    $worktree = false;
    $selection = null;
    foreach ($args as $arg) {
        if ($arg === '--worktree' && !$worktree) {
            $worktree = true;
        } elseif (str_starts_with($arg, '--ref=') && $ref === null && strlen($arg) > 6) {
            $ref = substr($arg, 6);
        } elseif ($selection === null && ($arg === '--all' || !str_starts_with($arg, '-'))) {
            $selection = $arg;
        } else {
            fwrite(STDERR, "archive-check: ambiguous/unknown argument {$arg}\n");

            return 2;
        }
    }
    if ($selection === null || ($worktree === ($ref !== null))) {
        fwrite(STDERR, "usage: archive-check.php <package>|--all --ref=COMMIT|--worktree\n");

        return 2;
    }
    $temp = sys_get_temp_dir().'/vaulter-archive-check-'.bin2hex(random_bytes(8));
    mkdir($temp);
    register_shutdown_function(static fn () => archiveRemove($temp));
    $sourceMap = [];
    if ($worktree) {
        $source = $temp.'/source';
        mkdir($source);
        $paths = explode("\0", archiveCommand(['git', '-C', $root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard', '--', 'release/packages.json', 'packages', 'CHANGELOG.md', 'LICENSE', 'SECURITY.md']));
        foreach (array_unique($paths) as $path) {
            if ($path === '' || !file_exists($root.'/'.$path) && !is_link($root.'/'.$path)) {
                continue;
            }
            if (!archiveSafePath($path) || is_link($root.'/'.$path) || !is_file($root.'/'.$path)) {
                throw new RuntimeException('unsafe source path: '.$path);
            }
            @mkdir(dirname($source.'/'.$path), 0775, true);
            copy($root.'/'.$path, $source.'/'.$path);
            chmod($source.'/'.$path, fileperms($root.'/'.$path) & 0777);
            $sourceMap[$path] = ['sha256' => hash_file('sha256', $source.'/'.$path), 'mode' => fileperms($source.'/'.$path) & 0111 ? '100755' : '100644'];
        }
        archiveCommand(['git', 'init', '-q', $source]);
        // Plumbing bypasses candidate .gitattributes clean filters, ignore rules and commit hooks.
        $indexInfo = '';
        foreach ($sourceMap as $path => $meta) {
            $oid = trim(archiveCommand(['git', '-C', $source, 'hash-object', '-w', '--no-filters', $source.'/'.$path]));
            $indexInfo .= $meta['mode'].' '.$oid."\t".$path."\n";
        }
        file_put_contents($temp.'/index-info', $indexInfo);
        archiveCommand(['git', '-C', $source, 'update-index', '--index-info'], input: $temp.'/index-info');
        $tree = trim(archiveCommand(['git', '-C', $source, 'write-tree']));
        $ref = trim(archiveCommand(['git', '-C', $source, '-c', 'user.name=archive-snapshot', '-c', 'user.email=archive@invalid', 'commit-tree', $tree, '-m', 'isolated worktree content snapshot']));
        $root = $source;
    }
    $sha = trim(archiveCommand(['git', '-C', $root, 'rev-parse', '--verify', '--end-of-options', $ref.'^{commit}']));
    $manifest = json_decode(archiveCommand(['git', '-C', $root, 'show', $sha.':release/packages.json']), true, 512, JSON_THROW_ON_ERROR);
    if (($errors = validateManifest($manifest)) !== []) {
        throw new RuntimeException(implode('; ', $errors));
    }
    foreach ($manifest['php'] as $package) {
        if (!archiveSafePath($package['path']) || !preg_match('~^packages/[a-z0-9-]+$~', $package['path'])) {
            throw new RuntimeException('unsafe package path');
        }
        foreach (explode("\0", archiveCommand(['git', '-C', $root, 'ls-tree', '-rz', $sha, '--', $package['path']])) as $entry) {
            if (str_starts_with($entry, '120000 ') || str_starts_with($entry, '160000 ')) {
                throw new RuntimeException('symlink/submodule in source tree');
            }
        }
    }
    $reports = [];
    foreach (['public', 'private'] as $channel) {
        $out = $temp.'/'.$channel;
        archiveCommand([PHP_BINARY, __DIR__.'/build-artifacts.php', '--root='.$root, '--ref='.$sha, '--version=1.0.0', '--channel='.$channel, '--out='.$out]);
        foreach ($manifest['php'] as $package) {
            if ($package['channel'] !== $channel || !in_array($selection, ['--all', $package['name'], basename($package['path'])], true)) {
                continue;
            }
            $recordPath = $out.'/prepared/'.str_replace('/', '-', $package['name']).'.json';
            if (!is_file($recordPath) && ($package['excluded_unless_tagged'] ?? false)) {
                $reports[] = ['package' => $package['name'], 'status' => 'excluded_untagged'];

                continue;
            }
            $record = json_decode((string) file_get_contents($recordPath), true, 512, JSON_THROW_ON_ERROR);
            $errors = archiveContentErrors($out.'/'.$record['archive'], $package, $manifest);
            $reports[] = ['package' => $package['name'], 'status' => $errors === [] ? 'content_pass' : 'failed', 'errors' => $errors, 'prepared' => $record];
        }
    }
    if ($reports === []) {
        throw new RuntimeException('unknown package selection');
    }
    ksort($sourceMap);
    echo json_encode(['schema' => 'vaulter.archive-content/v1', 'source_kind' => $worktree ? 'isolated_worktree' : 'commit', 'source_sha' => $sha, 'source_inputs' => $sourceMap, 'source_content_sha256' => hash('sha256', json_encode($sourceMap, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'packages' => $reports, 'pending' => ['SECURITY.md and copied license policy: P7.5 prerequisite', 'runtime/installed consumer, trusted Tool T, external owner A, registry and signatures: P8.2'], 'artifact_qualification' => 'pending'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

    return array_filter($reports, static fn (array $r): bool => $r['status'] === 'failed') === [] ? 0 : 1;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        exit(archiveMain(array_slice($argv, 1)));
    } catch (Throwable $e) {
        fwrite(STDERR, 'archive-check: '.$e->getMessage()."\n");
        exit(1);
    }
}
