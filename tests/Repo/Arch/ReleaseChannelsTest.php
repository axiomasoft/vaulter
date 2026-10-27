<?php

declare(strict_types=1);

/** Manifest ownership controls both dependency edges and shipped text. */
function rcErrors(array $owners, array $files, array $allowlist): array
{
    $errors = [];
    $used = [];
    $private = [];
    foreach ($owners as $owner) {
        if (!in_array($owner['channel'], ['public', 'private'], true)) {
            $errors[] = 'invalid channel: '.$owner['name'];
        }
        if ($owner['channel'] === 'private') {
            $private[] = $owner['name'];
            $private[] = basename($owner['name']);
            $private[] = $owner['manifest']['name'] ?? $owner['name'];
        }
    }
    $patterns = array_unique([...$private, 'Vaulter\\Collaboration\\', 'Vaulter\\\\Collaboration\\\\', 'collaboration-hub', 'Hocuspocus', 'hocuspocus']);
    foreach ($owners as $owner) {
        $manifest = $owner['manifest'];
        $expected = $owner['channel'] === 'public' ? 'MIT' : ($owner['kind'] === 'php' ? 'proprietary' : 'UNLICENSED');
        if (($manifest['license'] ?? null) !== $expected) {
            $errors[] = 'license: '.$owner['name'];
        }
        if ($owner['channel'] !== 'public') {
            continue;
        }
        foreach (['require', 'require-dev', 'suggest', 'dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $field) {
            foreach (array_keys($manifest[$field] ?? []) as $dependency) {
                if (in_array($dependency, $private, true)) {
                    $errors[] = 'private edge: '.$field.': '.$dependency;
                }
            }
        }
        $manifestPath = $owner['path'].'/'.($owner['kind'] === 'php' ? 'composer.json' : 'package.json');
        $ownedFiles = $files;
        $ownedFiles[$manifestPath] = ($ownedFiles[$manifestPath] ?? '')."\n".json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach ($ownedFiles as $path => $contents) {
            if (!str_starts_with($path, $owner['path'].'/')) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (!str_contains($contents, $pattern)) {
                    continue;
                }
                $allowed = false;
                foreach ($allowlist as $i => $entry) {
                    // Permit an exact literal excerpt only, never a namespace or wildcard exemption.
                    $literal = $entry['pattern'] ?? '';
                    if (($entry['path'] ?? '') === $path && !strpbrk($path, '*?[]') && trim($entry['reason'] ?? '') !== '' && strlen($literal) > strlen($pattern) && str_contains($literal, $pattern) && str_contains($contents, $literal) && !str_contains(str_replace($literal, '', $contents), $pattern)) {
                        $used[$i] = true;
                        $allowed = true;
                    }
                }
                if (!$allowed) {
                    $errors[] = 'private mention: '.$path.': '.$pattern;
                }
            }
        }
    }
    foreach ($allowlist as $i => $entry) {
        if (!isset($used[$i])) {
            $errors[] = 'stale or broad allowlist: '.$i;
        }
    }

    return $errors;
}

it('keeps manifest owners licenses dependencies and shipped public files within their channels', function (): void {
    $root = dirname(__DIR__, 3);
    $release = json_decode(file_get_contents($root.'/release/packages.json'), true, flags: JSON_THROW_ON_ERROR);
    $owners = [];
    $files = [];
    foreach (['php' => 'composer.json', 'npm' => 'package.json'] as $kind => $filename) {
        foreach ($release[$kind] as $owner) {
            $owner['kind'] = $kind;
            $owner['manifest'] = json_decode(file_get_contents($root.'/'.$owner['path'].'/'.$filename), true, flags: JSON_THROW_ON_ERROR);
            $owners[] = $owner;
            foreach ([$filename, 'README.md', 'src', 'config', 'routes', 'resources', 'database'] as $part) {
                $base = $root.'/'.$owner['path'].'/'.$part;
                if (is_file($base)) {
                    $files[$owner['path'].'/'.$part] = file_get_contents($base);
                } elseif (is_dir($base)) {
                    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
                        if ($file->isFile()) {
                            $files[substr($file->getPathname(), strlen($root) + 1)] = file_get_contents($file->getPathname());
                        }
                    }
                }
            }
        }
    }
    foreach ($release['images'] as $owner) {
        $owner['kind'] = 'npm';
        $owner['manifest'] = json_decode(file_get_contents($root.'/'.$owner['path'].'/package.json'), true, flags: JSON_THROW_ON_ERROR);
        $owners[] = $owner;
    }
    $allowlist = json_decode(file_get_contents(__DIR__.'/Fixtures/allowlists/private-mentions.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(rcErrors($owners, $files, $allowlist))->toBe([]);
});

it('rejects private edges metadata contents licenses and stale or namespace exceptions', function (): void {
    $public = ['name' => 'public/pkg', 'path' => 'packages/public', 'kind' => 'php', 'channel' => 'public', 'manifest' => ['license' => 'MIT']];
    $private = ['name' => 'private/addon', 'path' => 'packages/private', 'kind' => 'npm', 'channel' => 'private', 'manifest' => ['license' => 'UNLICENSED']];
    expect(rcErrors([$public, $private], [], []))->toBe([]);
    foreach (['require', 'require-dev', 'suggest', 'dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $field) {
        $bad = $public;
        $bad['manifest'][$field]['private/addon'] = '^1.0';
        expect(implode('\n', rcErrors([$bad, $private], [], [])))->toContain('private edge: '.$field);
    }
    foreach (['composer.json', 'package.json', 'src/Example.php', 'README.md'] as $file) {
        expect(implode('\n', rcErrors([$public, $private], ['packages/public/'.$file => 'description private/addon'], [])))->toContain('private mention');
    }
    foreach (['description', 'suggest'] as $field) {
        $metadata = $public;
        $metadata['manifest'][$field] = $field === 'description' ? 'Private private/addon implementation' : ['public/other' => 'Install private/addon for transport'];
        expect(implode('\n', rcErrors([$metadata, $private], [], [])))->toContain('private mention');
    }
    $entry = ['path' => 'packages/public/README.md', 'pattern' => 'Optional add-on: private/addon.', 'reason' => 'Exact neutral add-on notice'];
    expect(rcErrors([$public, $private], [$entry['path'] => $entry['pattern']], [$entry]))->toBe([]);
    expect(implode('\n', rcErrors([$public, $private], [], [$entry])))->toContain('stale or broad allowlist');
    $invalid = $private;
    $invalid['channel'] = 'unknown';
    expect(implode('\n', rcErrors([$public, $invalid], [], [])))->toContain('invalid channel');
    $private['manifest']['license'] = 'MIT';
    expect(implode('\n', rcErrors([$public, $private], [], [])))->toContain('license');
    $public['manifest']['license'] = 'proprietary';
    expect(implode('\n', rcErrors([$public, $private], [], [])))->toContain('license');
    foreach (['packages/**', 'packages/public/src/Example.php'] as $path) {
        expect(implode('\n', rcErrors([$public, $private], ['packages/public/src/Example.php' => 'Vaulter\\Collaboration\\Secret'], [['path' => $path, 'pattern' => 'Vaulter\\Collaboration\\', 'reason' => 'fixture']])))->toContain('stale or broad allowlist');
    }
});
