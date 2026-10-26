<?php

declare(strict_types=1);

/*
 * scripts/release/consumer-check.sh, the I14 clean-consumer harness (PLAN3 P4.19). Two kinds of check, both offline and
 * without a Laravel application, a database or the network:
 *
 *  - the static refusal (before anything is installed): a package that names a class of a sibling it does not declare,
 *    reads `../core/tests` or takes the kernel's `Vaulter\Tests` namespace must fail - on a fixture monorepo, so the
 *    harness is proven to refuse and not to pass for want of looking;
 *  - the build: with a stand-in `composer` and `pest` the staged work directory is inspected - path repositories on the
 *    declared siblings only, `self.version` pinned, no vendor/ of the repository anywhere.
 *
 * The real `composer install` and the package tests run in CI (job consumer-isolation) and in PLAN4, never here (D41).
 */

function c_root(): string
{
    return dirname(__DIR__, 2);
}

function c_rmdir(string $dir): void
{
    if (!is_dir($dir) && !is_link($dir)) {
        return;
    }
    if (is_link($dir)) {
        unlink($dir);

        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function c_write(string $root, string $path, string $contents, bool $executable = false): void
{
    is_dir(dirname($root.'/'.$path)) || mkdir(dirname($root.'/'.$path), 0o777, true);
    file_put_contents($root.'/'.$path, $contents);
    $executable && chmod($root.'/'.$path, 0o755);
}

function c_tmp(): string
{
    $dir = sys_get_temp_dir().'/vaulter-consumer-'.bin2hex(random_bytes(5));
    mkdir($dir, 0o777, true);

    return $dir;
}

/**
 * @param  list<string>                        $args
 * @param  array<string, string>               $env
 * @return array{0: int, 1: string, 2: string}
 */
function c_run(array $args, array $env = []): array
{
    $clean = array_filter(getenv(), static fn (string $key): bool => preg_match('/^(DB_|VAULTER_|CONSUMER_|PHP84_BIN|COMPOSER_)/', $key) !== 1, ARRAY_FILTER_USE_KEY);
    $process = proc_open(['bash', c_root().'/scripts/release/consumer-check.sh', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge($clean, ['PHP84_BIN' => PHP_BINARY], $env));
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $out, $err];
}

/**
 * A throw-away monorepo: core (Vaulter\), alpha (needs core), beta (needs core + alpha), gamma (needs core, unrelated to beta)
 * and a metapackage. Sources are written by the test through $files: path under the fixture => contents.
 *
 * @param array<string, string>                $files
 * @param array<string, array<string, string>> $requires package => extra require entries of its composer.json
 */
function c_fixture(array $files = [], array $requires = []): string
{
    $root = c_tmp();
    $manifest = ['schema' => 'vaulter.release/v1', 'php' => [
        ['name' => 'acme/vaulter-core', 'path' => 'packages/core', 'split' => 'acme/vaulter-core'],
        ['name' => 'acme/vaulter-alpha', 'path' => 'packages/alpha', 'split' => 'acme/vaulter-alpha'],
        ['name' => 'acme/vaulter-beta', 'path' => 'packages/beta', 'split' => 'acme/vaulter-beta'],
        ['name' => 'acme/vaulter-gamma', 'path' => 'packages/gamma', 'split' => 'acme/vaulter-gamma'],
        ['name' => 'acme/vaulter-waiting', 'path' => 'packages/waiting', 'split' => 'acme/vaulter-waiting', 'requires_tag' => 'acme/upstream', 'excluded_unless_tagged' => true],
        ['name' => 'acme/vaulter', 'path' => 'packages/meta', 'split' => 'acme/vaulter', 'metapackage' => true],
    ]];
    c_write($root, 'release/packages.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $packages = [
        'core' => ['acme/vaulter-core', 'Vaulter\\', [], []],
        'alpha' => ['acme/vaulter-alpha', 'Vaulter\\Alpha\\', ['acme/vaulter-core' => 'self.version'], []],
        'beta' => ['acme/vaulter-beta', 'Vaulter\\Beta\\', ['acme/vaulter-core' => 'self.version', 'acme/vaulter-alpha' => 'self.version'], ['pestphp/pest' => '^4.0']],
        'gamma' => ['acme/vaulter-gamma', 'Vaulter\\Gamma\\', ['acme/vaulter-core' => 'self.version'], []],
        'waiting' => ['acme/vaulter-waiting', 'Vaulter\\Waiting\\', ['acme/upstream' => 'dev-main'], []],
    ];
    foreach ($packages as $directory => [$name, $namespace, $require, $dev]) {
        $composer = [
            'name' => $name,
            'require' => ['php' => '^8.4', ...$require, ...($requires[$directory] ?? [])],
            'require-dev' => $dev,
            'autoload' => ['psr-4' => [$namespace => 'src/']],
            'repositories' => [['type' => 'path', 'url' => '../core', 'options' => ['symlink' => true]]],
        ];
        c_write($root, "packages/{$directory}/composer.json", json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        c_write($root, "packages/{$directory}/src/Marker.php", "<?php\n\nnamespace {$namespace}Support;\n\nfinal class Marker {}\n");
        c_write($root, "packages/{$directory}/tests/Pest.php", "<?php\n");
        c_write($root, "packages/{$directory}/phpunit.xml", "<phpunit/>\n");
        // installed state that must never be copied into the consumer
        c_write($root, "packages/{$directory}/vendor/autoload.php", "<?php\n");
        c_write($root, "packages/{$directory}/composer.lock", "{}\n");
    }
    c_write($root, 'packages/meta/composer.json', json_encode(['name' => 'acme/vaulter', 'type' => 'metapackage', 'require' => ['acme/vaulter-core' => 'self.version']]));

    foreach ($files as $path => $contents) {
        c_write($root, $path, $contents);
    }

    return $root;
}

/** A stand-in composer: records what it was given and writes installed.json for the path repositories. */
function c_fake_tools(string $dir, string $extraPackage = ''): array
{
    $composer = <<<'PHP'
#!/usr/bin/env php
<?php
$log = getenv('CONSUMER_FAKE_LOG');
$json = json_decode(file_get_contents('composer.json'), true);
file_put_contents($log.'/composer.json', json_encode($json));
file_put_contents($log.'/composer.env', json_encode(['cwd' => getcwd(), 'root_version' => getenv('COMPOSER_ROOT_VERSION'), 'argv' => array_slice($argv, 1)]));
$packages = [];
foreach ($json['repositories'] as $repository) {
    $packages[] = ['name' => json_decode(file_get_contents($repository['url'].'/composer.json'), true)['name']];
}
if (getenv('CONSUMER_FAKE_EXTRA') !== false && getenv('CONSUMER_FAKE_EXTRA') !== '') {
    $packages[] = ['name' => getenv('CONSUMER_FAKE_EXTRA')];
}
mkdir('vendor/composer', 0777, true);
file_put_contents('vendor/composer/installed.json', json_encode(['packages' => $packages]));
mkdir('vendor/bin', 0777, true);
file_put_contents('vendor/bin/pest', "<?php\nfile_put_contents(getenv('CONSUMER_FAKE_LOG').'/pest.json', json_encode(['cwd' => getcwd(), 'argv' => array_slice(\$argv, 1), 'env' => getenv('DB_CONNECTION')]));\n");
PHP;
    c_write($dir, 'composer', $composer, true);

    return ['CONSUMER_COMPOSER' => $dir.'/composer', 'CONSUMER_FAKE_LOG' => $dir, 'CONSUMER_FAKE_EXTRA' => $extraPackage];
}

dataset('consumer packages', ['core', 'media', 'drive', 'documents', 'webhooks', 'collaboration']);

it('plans the declared siblings of every consumer package of the repository and touches nothing', function (string $package): void {
    $work = c_tmp();
    [$code, $out, $err] = c_run([$package, '--dry-run', "--work-dir={$work}/consumer"]);

    expect($code)->toBe(0, $out.$err)
        ->and($out)->toContain('dry run OK')
        ->and(is_dir("{$work}/consumer"))->toBeFalse('a dry run builds nothing');

    c_rmdir($work);
})->with('consumer packages');

it('lists exactly the declared siblings for webhooks and no other package', function (): void {
    [$code, $out, $err] = c_run(['webhooks', '--dry-run']);

    expect($code)->toBe(0, $out.$err);
    preg_match('/^  repositories: (.*)$/m', $out, $match);
    expect($match[1] ?? '')->toBe('axioma-studio/vaulter-core axioma-studio/vaulter-drive axioma-studio/vaulter-media')
        ->and($out)->not->toContain('vaulter-documents')
        ->and($out)->not->toContain('vaulter-filament');
});

it('lists exactly the declared siblings for collaboration, its dev sibling included, and no other package', function (): void {
    [$code, $out, $err] = c_run(['collaboration', '--dry-run']);

    expect($code)->toBe(0, $out.$err);
    preg_match('/^  repositories: (.*)$/m', $out, $match);
    expect($match[1] ?? '')->toBe('axioma-studio/vaulter-core axioma-studio/vaulter-documents axioma-studio/vaulter-drive axioma-studio/vaulter-media')
        ->and($out)->not->toContain('vaulter-webhooks')
        ->and($out)->not->toContain('vaulter-filament');
});

it('does not read the repository vendor or the core tests', function (): void {
    $script = (string) file_get_contents(c_root().'/scripts/release/consumer-check.sh');

    expect($script)->not->toContain('$root/vendor')
        ->and($script)->not->toContain('vendor/autoload')
        ->and($script)->not->toMatch('#\$root/packages/core/tests#');
});

it('refuses an unknown package, the metapackage and a package that waits for an upstream tag', function (): void {
    $root = c_fixture();

    foreach (['nonesuch', 'meta', 'acme/vaulter', 'waiting'] as $package) {
        [$code, , $err] = c_run([$package, '--dry-run', "--root={$root}"]);
        expect($code)->toBe(2, "{$package}: {$err}");
    }
    [$code] = c_run(['--dry-run']);
    expect($code)->toBe(2);
    [$code] = c_run(['alpha', 'beta', '--dry-run', "--root={$root}"]);
    expect($code)->toBe(2);

    c_rmdir($root);
});

it('passes a fixture package that names only what it declares', function (): void {
    $root = c_fixture([
        'packages/beta/src/UsesAlpha.php' => "<?php\n\nnamespace Vaulter\\Beta;\n\nuse Vaulter\\Alpha\\Support\\Marker;\nuse Vaulter\\Operations\\Runner;\n\nfinal class UsesAlpha {}\n",
    ]);
    [$code, $out, $err] = c_run(['beta', '--dry-run', "--root={$root}"]);

    expect($code)->toBe(0, $out.$err);
    preg_match('/^  repositories: (.*)$/m', $out, $match);
    expect($match[1])->toBe('acme/vaulter-alpha acme/vaulter-core');

    c_rmdir($root);
});

it('fails a package that imports a class of a sibling it does not declare', function (): void {
    // gamma exists in the monorepo and in the root vendor of a naive harness, but beta never requires it
    $root = c_fixture(['packages/beta/src/Leak.php' => "<?php\n\nnamespace Vaulter\\Beta;\n\nuse Vaulter\\Gamma\\Support\\Marker;\n\nfinal class Leak {}\n"]);
    [$code, , $err] = c_run(['beta', '--dry-run', "--root={$root}"]);

    expect($code)->toBe(1)
        ->and($err)->toContain('src/Leak.php:5')
        ->and($err)->toContain('Vaulter\\Gamma\\Support\\Marker')
        ->and($err)->toContain('acme/vaulter-gamma')
        ->and($err)->toContain('does not declare');

    c_rmdir($root);
});

it('fails the undeclared sibling in the tests of the package and when only a dependency declares it', function (): void {
    // alpha declares gamma, beta only declares alpha: a transitive sibling still has to be required by the one that uses it
    $root = c_fixture(
        ['packages/beta/tests/Feature/LeakTest.php' => "<?php\n\nuse Vaulter\\Gamma\\Support\\Marker;\n\nit('x', fn () => new Marker());\n"],
        ['alpha' => ['acme/vaulter-gamma' => 'self.version']],
    );
    [$code, , $err] = c_run(['beta', '--dry-run', "--root={$root}"]);

    expect($code)->toBe(1)
        ->and($err)->toContain('tests/Feature/LeakTest.php:3');

    c_rmdir($root);
});

it('catches a class-string of a sibling in src but not a forbidden namespace spelled in a test', function (): void {
    $root = c_fixture([
        'packages/beta/src/config.php' => "<?php\n\nreturn ['model' => 'Vaulter\\\\Gamma\\\\Models\\\\Thing'];\n",
        // an architecture test names what it forbids: strings and heredoc fixtures are not references
        'packages/alpha/tests/Arch/BoundaryTest.php' => "<?php\n\nexpect('Vaulter\\\\Beta')->toBeString();\n\$code = <<<'PHP'\nuse Vaulter\\Beta\\Models\\Thing;\nPHP;\n",
    ]);

    [$code, , $err] = c_run(['beta', '--dry-run', "--root={$root}"]);
    expect($code)->toBe(1)
        ->and($err)->toContain('src/config.php:3');

    [$code, $out, $err] = c_run(['alpha', '--dry-run', "--root={$root}"]);
    expect($code)->toBe(0, $out.$err);

    c_rmdir($root);
});

it('fails a package whose tests reach into another package tests, and one that takes the kernel test namespace', function (): void {
    $root = c_fixture([
        'packages/beta/tests/Pest.php' => "<?php\n\nrequire __DIR__.'/../../core/tests/Concerns/Helpers.php';\n",
        'packages/alpha/tests/UsesKernelTestCase.php' => "<?php\n\nuse Vaulter\\Integration\\Tests\\TestCase;\n\nuses(TestCase::class);\n",
    ]);

    [$code, , $err] = c_run(['beta', '--dry-run', "--root={$root}"]);
    expect($code)->toBe(1)
        ->and($err)->toContain('tests/Pest.php:3')
        ->and($err)->toContain("reaches another package's tests");

    [$code, , $err] = c_run(['alpha', '--dry-run', "--root={$root}"]);
    expect($code)->toBe(1)
        ->and($err)->toContain("kernel's test namespace");

    c_rmdir($root);
});

it('also refuses the undeclared dependency when it would build for real', function (): void {
    $root = c_fixture(['packages/beta/src/Leak.php' => "<?php\n\nnamespace Vaulter\\Beta;\n\nuse Vaulter\\Gamma\\Support\\Marker;\n"]);
    $tools = c_tmp();
    $work = c_tmp();
    [$code] = c_run(['beta', "--root={$root}", "--work-dir={$work}/consumer"], c_fake_tools($tools));

    expect($code)->toBe(1)
        ->and(file_exists("{$tools}/composer.env"))->toBeFalse('composer must not even start');

    c_rmdir($root);
    c_rmdir($tools);
    c_rmdir($work);
});

it('builds the consumer from the declared siblings only: path repositories, pinned versions, no installed state', function (): void {
    $root = c_fixture();
    $tools = c_tmp();
    $work = c_tmp();
    [$code, $out, $err] = c_run(['beta', "--root={$root}", "--work-dir={$work}/consumer"], c_fake_tools($tools));
    expect($code)->toBe(0, $out.$err);

    $composer = json_decode((string) file_get_contents("{$tools}/composer.json"), true);
    $urls = array_column($composer['repositories'], 'url');
    sort($urls);
    expect($urls)->toBe(['.deps/alpha', '.deps/core'])
        ->and(array_unique(array_column($composer['repositories'], 'type')))->toBe(['path'])
        ->and($composer['repositories'][0]['options']['versions'])->toBeArray()
        ->and($composer['require']['acme/vaulter-core'])->toBe('1.0.0')
        ->and($composer['require']['acme/vaulter-alpha'])->toBe('1.0.0')
        ->and(json_encode($composer))->not->toContain('self.version')
        ->and(json_encode($composer))->not->toContain('../');

    $env = json_decode((string) file_get_contents("{$tools}/composer.env"), true);
    expect(realpath($env['cwd']))->toBe(realpath("{$work}/consumer"))
        ->and($env['root_version'])->toBe('1.0.0')
        ->and($env['argv'])->toContain('install');

    // the package and its dependencies were copied without the installed state of the monorepo
    expect(file_exists("{$work}/consumer/vendor/autoload.php"))->toBeFalse('the vendor/ of the package leaked in')
        ->and(file_exists("{$work}/consumer/composer.lock"))->toBeFalse()
        ->and(is_link("{$work}/consumer/vendor"))->toBeFalse('vendor/ is the consumer\'s own, never a link to the repository')
        ->and(file_exists("{$work}/consumer/src/Marker.php"))->toBeTrue()
        ->and(file_exists("{$work}/consumer/.deps/core/src/Marker.php"))->toBeTrue()
        ->and(file_exists("{$work}/consumer/.deps/core/vendor"))->toBeFalse()
        ->and(file_exists("{$work}/consumer/.deps/core/tests"))->toBeFalse('a dependency brings no tests')
        ->and(file_exists("{$work}/consumer/.deps/alpha/composer.lock"))->toBeFalse()
        ->and(file_exists("{$work}/consumer/.deps/gamma"))->toBeFalse('an unrelated sibling is not on the path')
        ->and(file_exists("{$work}/consumer/.deps/beta"))->toBeFalse();

    $pest = json_decode((string) file_get_contents("{$tools}/pest.json"), true);
    expect(realpath($pest['cwd']))->toBe(realpath("{$work}/consumer"))
        ->and($pest['argv'])->toBe(['--ci', '--exclude-group=nofast'])
        ->and($pest['env'])->toBe('sqlite');

    c_rmdir($root);
    c_rmdir($tools);
    c_rmdir($work);
});

it('fails when the install brings in a Vaulter package nobody declared', function (): void {
    $root = c_fixture();
    $tools = c_tmp();
    $work = c_tmp();
    [$code, , $err] = c_run(['alpha', "--root={$root}", "--work-dir={$work}/consumer"], c_fake_tools($tools, 'acme/vaulter-gamma'));

    expect($code)->toBe(1)
        ->and($err)->toContain('undeclared Vaulter packages were installed')
        ->and($err)->toContain('acme/vaulter-gamma')
        ->and(file_exists("{$tools}/pest.json"))->toBeFalse('the tests must not run in a polluted consumer');

    c_rmdir($root);
    c_rmdir($tools);
    c_rmdir($work);
});

it('stages the root Pest harness next to core, which keeps phpunit.xml and tests/Pest.php at the repository root', function (): void {
    $tools = c_tmp();
    $work = c_tmp();
    [$code, $out, $err] = c_run(['core', "--work-dir={$work}/consumer"], c_fake_tools($tools));
    expect($code)->toBe(0, $out.$err);

    $composer = json_decode((string) file_get_contents("{$tools}/composer.json"), true);
    expect($composer['autoload']['psr-4'])->toBe(['Vaulter\\' => 'packages/core/src/'])
        ->and($composer['autoload-dev']['psr-4'])->toBe(['Vaulter\\Tests\\' => 'packages/core/tests/'])
        ->and(array_column($composer['repositories'], 'url'))->not->toContain('.deps/core')
        ->and(json_encode($composer))->not->toContain('self.version');
    foreach (['phpunit.xml', 'tests/Pest.php', 'packages/core/src', 'packages/core/tests'] as $staged) {
        expect(file_exists("{$work}/consumer/{$staged}"))->toBeTrue("{$staged} is staged for core");
    }
    expect(file_exists("{$work}/consumer/packages/core/vendor"))->toBeFalse()
        ->and(file_exists("{$work}/consumer/tests/zones.json"))->toBeFalse()
        ->and(file_exists("{$work}/consumer/tests/Integration"))->toBeFalse()
        ->and(file_exists("{$work}/consumer/load"))->toBeFalse()
        ->and(glob("{$work}/consumer/.deps/*"))->toBe([])
        ->and(file_get_contents("{$work}/consumer/tests/Pest.php"))->not->toContain('Integration', 'LoadTestCase', 'zones.json')
        ->and(file_get_contents("{$work}/consumer/phpunit.xml"))->not->toContain('tests/Integration', 'packages/drive/tests');

    $pest = json_decode((string) file_get_contents("{$tools}/pest.json"), true);
    expect($pest['argv'])->toBe(['--ci', '--exclude-group=nofast', '--testsuite=core']);

    c_rmdir($tools);
    c_rmdir($work);
});

it('plans core with no sibling repository after the kernel split', function (): void {
    [$code, $out, $err] = c_run(['core', '--dry-run']);
    expect($code)->toBe(0, $out.$err)->and($out)->toContain('repositories: (none)');
});
