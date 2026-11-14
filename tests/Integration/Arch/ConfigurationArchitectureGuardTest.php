<?php

declare(strict_types=1);

use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;
use Vaulter\Integration\Tests\Support\ConfigurationArchitectureGuard;

test('AST configuration guard rejects retired names even without autoloadable classes', function (string $code): void {
    $violations = (new ConfigurationArchitectureGuard)->scanSource("<?php\n".$code, 'probe.php');

    expect($violations)->not->toBeEmpty()
        ->and($violations[0])->toStartWith('probe.php:2: retired configuration helper');
})->with([
    'unused import' => ['use Vaulter\\Support\\Config;'],
    'unused alias' => ['use Vaulter\\Support\\GateConfig as LegacyGate;'],
    'grouped aliases' => ['use Vaulter\\Support\\{Config as Legacy, SpatieConfig};'],
    'mixed grouped import' => ['use Vaulter\\Support\\{function config, GateConfig as Legacy};'],
    'namespace alias' => ['use Vaulter\\Support as Legacy; Legacy\\Config::models();'],
    'direct fully qualified' => ['\\Vaulter\\Support\\SpatieConfig::roleModel();'],
    'namespace relative' => ['namespace Vaulter\\Support; Config::models();'],
    'upload resolver' => ['use Vaulter\\Drive\\Support\\Uploads\\UploadModels;'],
    'former upload resolver name' => ['use Vaulter\\Drive\\Uploads\\UploadModels;'],
    'document resolver' => ['use Vaulter\\Documents\\DocumentsModels;'],
    'parameter type' => ['function configure(\\Vaulter\\Support\\Config $value): void {}'],
    'attribute' => ['#[\\Vaulter\\Support\\Config] class Probe {}'],
    'restored declaration' => ['namespace Vaulter\\Support; class Config {}'],
    'plain Pest file' => ["test('probe', fn () => \\Vaulter\\Support\\Config::models());"],
]);

test('AST configuration guard rejects literal class strings at consuming calls', function (string $code): void {
    $violations = (new ConfigurationArchitectureGuard)->scanSource("<?php\n".$code, 'consumer.php');

    expect($violations)->not->toBeEmpty()
        ->and($violations[0])->toStartWith('consumer.php:2: retired configuration helper');
})->with([
    'app' => ["app('Vaulter\\Support\\Config');"],
    'resolve named argument' => ["resolve(abstract: 'Vaulter\\Support\\GateConfig');"],
    'aliased helper' => ["use function app as container; container('Vaulter\\Support\\Config');"],
    'container make' => ["\$container->make('Vaulter\\Support\\Config');"],
    'container singleton implementation' => ["\$app->singleton('safe', 'Vaulter\\Support\\Config');"],
    'named binding implementation' => ["\$app->bind(concrete: 'Vaulter\\Support\\Config', abstract: 'safe');"],
    'reflection' => ["new \\ReflectionClass('Vaulter\\Support\\Config');"],
    'aliased reflection' => ["use ReflectionMethod as Inspector; new Inspector('Vaulter\\Support\\Config', 'models');"],
    'named reflection' => ["new \\ReflectionProperty(objectOrClass: 'Vaulter\\Support\\Config', property: 'model');"],
    'class existence' => ["class_exists('Vaulter\\Support\\Config');"],
    'subclass check' => ["is_subclass_of('Safe', 'Vaulter\\Support\\Config');"],
    'literal concatenation' => ["app('Vaulter\\\\Support\\\\'.'Config');"],
    'case insensitive name' => ["app('\\VAULTER\\support\\config');"],
]);

test('AST configuration guard permits comments and translation data without file exclusions', function (): void {
    $source = <<<'PHP'
<?php
// use Vaulter\Support\Config; config('vaulter.models');
/** @see \Vaulter\Support\GateConfig */
$translations = ['Vaulter\Support\Config' => 'Vaulter\Configuration\VaulterConfig'];
$description = 'app("Vaulter\\Support\\Config")';
return ['Vaulter\Support\SpatieConfig', 'Vaulter\Documents\DocumentsModels'];
PHP;

    expect((new ConfigurationArchitectureGuard)->scanSource($source, 'src/Configuration/ConfigNormalizer.php'))->toBe([]);
});

test('AST configuration guard recognizes direct config helper aliases', function (string $code): void {
    $violations = (new ConfigurationArchitectureGuard)->scanSource("<?php\n".$code, 'runtime.php');

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toStartWith('runtime.php:2: direct config()');
})->with([
    'global helper' => ["config('vaulter.models');"],
    'fully qualified helper' => ["\\config('vaulter.models');"],
    'namespace fallback' => ["namespace Vaulter\\Probe; config('vaulter.models');"],
    'import alias' => ["use function config as settings; settings('vaulter.models');"],
    'first class helper' => ['config(...);'],
]);

test('AST configuration guard fails closed on malformed PHP with its source line', function (): void {
    $violations = (new ConfigurationArchitectureGuard)->scanSource("<?php\n\nfunction broken( {", 'malformed.php');

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toStartWith('malformed.php:3: cannot parse/resolve PHP:');
});

test('AST configuration guard fails closed on unresolved inventory', function (): void {
    $path = sys_get_temp_dir().'/vaulter-missing-'.bin2hex(random_bytes(12));
    $violations = (new ConfigurationArchitectureGuard)->scanPackages($path);

    expect($violations)->toHaveCount(18)
        ->and(implode("\n", $violations))->toContain($path.'/core/src:1: cannot inventory')
        ->toContain($path.'/drive/tests:1: cannot inventory')
        ->toContain($path.'/media/config:1: cannot inventory');
});

test('AST inventory covers every package scope and applies only the owning source exception', function (): void {
    $temporary = sys_get_temp_dir().'/vaulter-architecture-'.bin2hex(random_bytes(12));
    $packages = ['core', 'drive', 'documents', 'media', 'filament', 'azguard'];

    try {
        foreach ($packages as $package) {
            foreach (['src', 'tests', 'config', 'database', 'routes'] as $scope) {
                $directory = $temporary.'/'.$package.'/'.$scope;
                if (!is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }
                file_put_contents($directory.'/unused-import.php', '<?php use Vaulter\\Support\\Config as Legacy;');
            }
        }
        mkdir($temporary.'/core/src/Configuration');
        mkdir($temporary.'/core/src/Other/Configuration', 0777, true);
        file_put_contents($temporary.'/core/src/Configuration/Owned.php', "<?php config('vaulter.models');");
        file_put_contents($temporary.'/core/src/Other/Configuration/Bypass.php', "<?php config('vaulter.models');");
        file_put_contents($temporary.'/core/tests/configuration-read.php', "<?php config('vaulter.models');");
        $violations = (new ConfigurationArchitectureGuard)->scanPackages($temporary);

        expect($violations)->toHaveCount(31);
        foreach ($packages as $package) {
            foreach (['src', 'tests', 'config', 'database', 'routes'] as $scope) {
                expect(implode("\n", $violations))->toContain($temporary.'/'.$package.'/'.$scope.'/unused-import.php:1: retired configuration helper');
            }
        }
        expect(implode("\n", $violations))->toContain('/core/src/Other/Configuration/Bypass.php:1: direct config()')
            ->not->toContain('/Owned.php:')->not->toContain('/configuration-read.php:');

        // Broken file links reproduce read failure even when the suite runs as root.
        symlink($temporary.'/missing.php', $temporary.'/core/tests/unreadable.php');
        unlink($temporary.'/drive/src/unused-import.php');
        unlink($temporary.'/media/config/unused-import.php');
        rmdir($temporary.'/media/config');
        $failures = implode("\n", (new ConfigurationArchitectureGuard)->scanPackages($temporary));
        expect($failures)->toContain('/core/tests/unreadable.php:1: cannot read PHP file')
            ->toContain('/drive/src:1: required package scope contains no PHP files')
            ->toContain('/media/config:1: cannot inventory required package scope');
    } finally {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($temporary);
    }
});

test('core never reads another package\'s configuration keys directly', function (): void {
    // ADR-0019: the package that owns a key answers questions about it through a contract
    // (ScanWorkSettings, CommentsFeature). ConfigNormalizer alone names the replacement-map keys of every package.
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $offenders = [];
    foreach ((new Finder)->files()->in(__DIR__.'/../../../packages/core/src')->name('*.php') as $file) {
        if ($file->getRelativePathname() === 'Configuration/ConfigNormalizer.php') {
            continue;
        }
        foreach ((new NodeFinder)->findInstanceOf($parser->parse($file->getContents()) ?? [], String_::class) as $literal) {
            // P3-security-contracts C01: the canonical `vaulter.scan.*` keys accept the
            // published `vaulter-drive.uploads.scan.*` names as a compatibility alias.
            $alias = $file->getRelativePathname() === 'Configuration/ScanConfig.php' && $literal->value === 'vaulter-drive.uploads.scan.';
            if (!$alias && preg_match('/^vaulter-(?:drive|documents|media|azguard|filament)\./', $literal->value) === 1) {
                $offenders[] = $file->getRelativePathname().':'.$literal->getStartLine().' '.$literal->value;
            }
        }
    }

    expect($offenders)->toBe([]);
});
