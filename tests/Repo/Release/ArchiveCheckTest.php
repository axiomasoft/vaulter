<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3).'/scripts/release/archive-check.php';

function ac_package(bool $meta = false, string $channel = 'public'): array
{
    return ['name' => 'fixture/package', 'path' => 'packages/core', 'channel' => $channel, 'metapackage' => $meta];
}

function ac_files(bool $meta = false): array
{
    $composer = ['name' => 'fixture/package', 'license' => 'MIT', 'type' => $meta ? 'metapackage' : 'library'];
    if (!$meta) {
        $composer += ['autoload' => ['psr-4' => ['Fixture\\' => 'src/']], 'extra' => ['laravel' => ['providers' => ['Fixture\\VaulterServiceProvider']]]];
    }

    return ['composer.json' => json_encode($composer), ...($meta ? [] : [
        'src/VaulterServiceProvider.php' => '<?php throw new Exception("must never execute");',
        'config/vaulter.php' => '<?php return [];',
        'resources/schemas/doctor-v1.schema.json' => '{}',
        'database/migrations/fixture.php' => '<?php',
    ])];
}

function ac_errors(array $files, array $package = [], ?string $symlink = null): array
{
    $path = tempnam(sys_get_temp_dir(), 'archive-fixture-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($files as $name => $bytes) {
        $zip->addFromString($name, $bytes);
        if ($name === $symlink) {
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
    }
    $zip->close();
    try {
        return archiveContentErrors($path, $package ?: ac_package(), ['php' => [['name' => 'private/package', 'channel' => 'private']]]);
    } finally {
        unlink($path);
    }
}

it('accepts runtime blobs without executing candidate PHP', function (): void {
    expect(ac_errors(ac_files()))->toBe([]);
});

it('rejects forbidden and traversal members before extraction', function (string $path): void {
    expect(ac_errors([...ac_files(), $path => 'secret']))->not->toBe([]);
})->with(['tests/Test.php', 'vendor/autoload.php', 'node_modules/a.js', '.env', '.agents/rules', '.claude/a', '.swissknife/a', 'phpunit.xml', 'composer.lock', 'cache.cache', '../escape', '/absolute', 'src/../../escape', 'src\\escape', 'hub/src/cli.js', 'src/.env.secret', 'src/.git/config']);

it('rejects symlink members even inside allowed runtime directories', function (): void {
    expect(ac_errors([...ac_files(), 'src/link' => '/etc/passwd'], symlink: 'src/link'))->not->toBe([]);
});

it('requires nonempty provider config schema and migrations', function (string $path): void {
    $files = ac_files();
    $files[$path] = '';
    expect(ac_errors($files))->not->toBe([]);
    unset($files[$path]);
    expect(ac_errors($files))->not->toBe([]);
})->with(['src/VaulterServiceProvider.php', 'config/vaulter.php', 'resources/schemas/doctor-v1.schema.json', 'database/migrations/fixture.php']);

it('accepts a metapackage without src and enforces its declared type', function (): void {
    expect(ac_errors(ac_files(true), ac_package(true)))->toBe([]);
    expect(ac_errors(ac_files(true)))->not->toBe([]);
});

it('rejects composer identity license and public private dependency drift', function (string $mutation): void {
    $files = ac_files();
    $composer = json_decode($files['composer.json'], true);
    if ($mutation === 'dependency') {
        $composer['require'] = ['private/package' => '^1.0'];
    } else {
        $composer[$mutation] = 'wrong';
    }
    $files['composer.json'] = json_encode($composer);
    expect(ac_errors($files))->not->toBe([]);
})->with(['name', 'license', 'dependency']);

it('allows private collaboration docs while rejecting its separate hub', function (): void {
    $files = ac_files(true);
    $composer = json_decode($files['composer.json'], true);
    $composer['license'] = 'proprietary';
    $files['composer.json'] = json_encode($composer);
    $package = [...ac_package(true, 'private'), 'path' => 'packages/collaboration'];
    expect(ac_errors([...$files, 'docs/api.md' => 'private API'], $package))->toBe([])
        ->and(ac_errors([...$files, 'hub/package.json' => '{}'], $package))->not->toBe([])
        ->and(ac_errors([...ac_files(), 'docs/api.md' => 'private API']))->not->toBe([]);
});

it('reads canonical prepared ZIP bytes from a dirty isolated snapshot', function (): void {
    // A small source repository exercises the real builder and export attributes, without a package autoloader.
    $root = dirname(__DIR__, 3);
    $temp = sys_get_temp_dir().'/archive-source-fixture-'.bin2hex(random_bytes(6));
    mkdir($temp.'/release', 0777, true);
    mkdir($temp.'/scripts/release', 0777, true);
    mkdir($temp.'/packages/core', 0777, true);
    foreach (['archive-check.php', 'build-artifacts.php', 'manifest-lib.php', 'sbom.py'] as $tool) {
        copy($root.'/scripts/release/'.$tool, $temp.'/scripts/release/'.$tool);
    }
    $manifest = json_decode((string) file_get_contents($root.'/release/packages.json'), true);
    $manifest['php'] = [['name' => 'fixture/package', 'path' => 'packages/core', 'split' => 'fixture/package', 'channel' => 'public', 'release_line' => 'php-public', 'tag_prefix' => 'v', 'metapackage' => true]];
    file_put_contents($temp.'/release/packages.json', json_encode($manifest));
    file_put_contents($temp.'/packages/core/composer.json', ac_files(true)['composer.json']);
    file_put_contents($temp.'/packages/core/.gitattributes', '/.gitattributes export-ignore');
    try {
        archiveCommand(['git', 'init', '-q', $temp]);
        archiveCommand(['git', '-C', $temp, 'add', '.']);
        archiveCommand(['git', '-C', $temp, '-c', 'user.name=fixture', '-c', 'user.email=fixture@invalid', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'fixture']);
        file_put_contents($temp.'/packages/core/README.md', 'dirty bytes');
        $before = archiveCommand(['git', '-C', $temp, 'status', '--porcelain=v1']);
        $report = json_decode(archiveCommand([PHP_BINARY, $temp.'/scripts/release/archive-check.php', '--all', '--worktree']), true, flags: JSON_THROW_ON_ERROR);
        expect($report['source_kind'])->toBe('isolated_worktree')
            ->and($report['source_inputs']['packages/core/README.md']['sha256'])->toBe(hash('sha256', 'dirty bytes'))
            ->and($report['packages'][0]['prepared']['files']['README.md']['sha256'])->toBe(hash('sha256', 'dirty bytes'))
            ->and(archiveCommand(['git', '-C', $temp, 'status', '--porcelain=v1']))->toBe($before);
    } finally {
        archiveRemove($temp);
    }
});

it('requires declared autoload files and bin blobs without evaluating them', function (): void {
    foreach (['autoload', 'bin'] as $field) {
        $files = ac_files();
        $composer = json_decode($files['composer.json'], true);
        if ($field === 'autoload') {
            $composer['autoload']['files'] = ['src/bootstrap.php'];
            $path = 'src/bootstrap.php';
        } else {
            $composer['bin'] = ['bin/tool'];
            $path = 'bin/tool';
        }
        $files['composer.json'] = json_encode($composer);
        expect(ac_errors($files))->not->toBe([]);
        $files[$path] = '<?php throw new Exception("data only");';
        expect(ac_errors($files))->toBe([]);
    }
});

it('rejects duplicate ZIP names before any extraction', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'archive-duplicate-');
    try {
        archiveCommand(['python3', '-c', 'import sys,zipfile,warnings; warnings.simplefilter("ignore"); z=zipfile.ZipFile(sys.argv[1],"w"); z.writestr("composer.json","{}"); z.writestr("composer.json","{}"); z.close()', $path]);
        expect(implode('; ', archiveContentErrors($path, ac_package(true), ['php' => []])))->toContain('duplicate member');
    } finally {
        unlink($path);
    }
});

it('requires an explicit unambiguous source selector', function (): void {
    foreach ([['--all'], ['--all', '--worktree', '--ref=HEAD'], ['--all', '--worktree', '--unknown']] as $args) {
        expect(archiveMain($args))->toBe(2);
    }
});
