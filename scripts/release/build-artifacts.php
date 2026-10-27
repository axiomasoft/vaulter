#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Builds one composer "artifact" zip per releasable PHP package from the committed tree,
 * so the consumer smoke installs exactly what a dist archive would contain (git archive
 * honours export-ignore). No network access.
 *
 * Usage: build-artifacts.php --version=X.Y.Z --out=DIR [--root=DIR] [--ref=HEAD] [--channel=public|private]
 */

require __DIR__.'/manifest-lib.php';

$options = ['root' => dirname(__DIR__, 2), 'ref' => 'HEAD', 'channel' => 'public'];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(version|out|root|ref|channel)=(.+)$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "build-artifacts: unknown argument {$arg}\n");
        exit(2);
    }
}

$version = $options['version'] ?? null;
$out = $options['out'] ?? null;
if ($version === null || preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version) !== 1 || $out === null) {
    fwrite(STDERR, "usage: build-artifacts.php --version=X.Y.Z --out=DIR [--root=DIR] [--ref=HEAD]\n");
    exit(2);
}

$channel = $options['channel'];
if ($channel !== 'public' && $channel !== 'private') {
    fwrite(STDERR, "build-artifacts: unknown channel {$channel}\n");
    exit(2);
}
$root = rtrim($options['root'], '/');
$ref = $options['ref'];

function run(string $command, ?string &$output = null): int
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process);
}

function removeTree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function isDevConstraint(string $constraint): bool
{
    return manifestIsDevConstraint($constraint);
}

$manifest = json_decode((string) file_get_contents($root.'/release/packages.json'), true, 512, JSON_THROW_ON_ERROR);

if (!is_dir($out) && !mkdir($out, 0775, true)) {
    fwrite(STDERR, "build-artifacts: cannot create {$out}\n");
    exit(1);
}
$out = realpath($out);

$staging = sys_get_temp_dir().'/vaulter-artifacts-'.bin2hex(random_bytes(6));
mkdir($staging, 0775, true);
register_shutdown_function(static fn () => removeTree($staging));

// Fail closed on the WHOLE manifest (schema, channel, line, prefix, uniqueness) BEFORE filtering by channel: one
// malformed or unknown private entry breaks the public build too.
$manifestErrors = validateManifest($manifest);
if ($manifestErrors !== []) {
    fwrite(STDERR, "build-artifacts: invalid release manifest\n - ".implode("\n - ", $manifestErrors)."\n");
    exit(1);
}

$built = [];
foreach ($manifest['php'] as $package) {
    $name = $package['name'];
    if ($package['channel'] !== $channel) {
        continue;
    }

    $dir = $staging.'/'.basename($package['path']);
    mkdir($dir, 0775, true);
    $pipeline = sprintf('git -C %s archive --format=tar %s | tar -x -C %s', escapeshellarg($root), escapeshellarg($ref.':'.$package['path']), escapeshellarg($dir));
    $code = run('bash -o pipefail -c '.escapeshellarg($pipeline), $log);
    if ($code !== 0) {
        fwrite(STDERR, "build-artifacts: git archive failed for {$name}: {$log}\n");
        exit(1);
    }

    $composer = json_decode((string) file_get_contents($dir.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    $upstream = $package['requires_tag'] ?? null;
    if (($package['excluded_unless_tagged'] ?? false) && $upstream !== null && isDevConstraint((string) ($composer['require'][$upstream] ?? ''))) {
        echo "skip {$name}: requires {$upstream} {$composer['require'][$upstream]} (dev constraint, upstream not tagged)\n";

        continue;
    }

    $composer['version'] = $version;
    // Path repositories point at sibling checkouts and mean nothing inside a dist archive.
    unset($composer['repositories']);
    foreach (['require', 'require-dev'] as $section) {
        foreach ($composer[$section] ?? [] as $dependency => $constraint) {
            if ($constraint === 'self.version') {
                $composer[$section][$dependency] = $version;
            }
        }
    }
    file_put_contents($dir.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

    $zipPath = $out.'/'.str_replace('/', '-', $name).'-'.$version.'.zip';
    @unlink($zipPath);
    $zip = new ZipArchive;
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        fwrite(STDERR, "build-artifacts: cannot write {$zipPath}\n");
        exit(1);
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $entries = [];
    foreach ($files as $file) {
        $entries[substr($file->getPathname(), strlen($dir) + 1)] = $file->getPathname();
    }
    ksort($entries);
    foreach ($entries as $local => $path) {
        $zip->addFile($path, $local);
    }
    $zip->close();

    $built[] = $zipPath;
    echo "built {$zipPath}\n";
}

echo count($built)." artifact(s) for {$version}\n";
