<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** @return list<string> */
function kernelTestDependencies(string $source): array
{
    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
    $found = [];
    foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool => $node instanceof Node\Name || $node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\EncapsedStringPart) as $node) {
        $value = $node instanceof Node\Name ? $node->toString() : $node->value;
        if (preg_match('~Vaulter\\\\(?:Media|Drive|Documents|Webhooks|Collaboration|AzGuard|Filament|Integration|Load)(?:\\\\|$)|axioma-studio/vaulter-(?:media|drive|documents|webhooks|collaboration|azguard|filament)|(?:^|[/\\s])scripts/|tests/Integration|packages/[^/]+/tests|(?:\.\./)+(?:media|drive|documents|webhooks|collaboration|azguard|filament)/~', $value) === 1) {
            $found[] = $value;
        }
    }

    // Material runtime lookups couple a test to a module even without a type import.
    // Inert strings in expected arrays, comments and negative fixtures are not lookups.
    $upper = '/^(?:media|drive|documents|webhooks|collaboration|azguard|filament)$/';
    foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\New_ || $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\ArrayDimFetch) as $node) {
        if ($node instanceof Node\Expr\ArrayDimFetch) {
            if ($node->dim instanceof Node\Scalar\String_ && preg_match('/^(?:drive|media|webhooks|documents|collaboration)\./', $node->dim->value) === 1) {
                $found[] = $node->dim->value;
            }

            continue;
        }
        $arg = $node->args[0]->value ?? null;
        if (!$arg instanceof Node\Scalar\String_) {
            continue;
        }
        $name = $node instanceof Node\Expr\New_ ? ($node->class instanceof Node\Name ? $node->class->getLast() : '') : ($node->name instanceof Node\Name || $node->name instanceof Node\Identifier ? $node->name->toString() : '');
        $value = $arg->value;
        if (($name === 'ModuleId' || $name === 'entitiesOf') && preg_match($upper, $value) === 1
            || in_array($name, ['config_path'], true) && preg_match('/^vaulter-(?:media|drive|documents|webhooks|collaboration|azguard|filament)(?:\.|$)/', $value) === 1
            || in_array($name, ['hasTable', 'getColumns'], true) && preg_match('/^(?:v_)?(?:drives|nodes|node_grants|file_revisions|upload_sessions|blobs|blob_references)$/', $value) === 1) {
            $found[] = $value;
        }
    }

    return array_values(array_unique($found));
}

it('keeps every core PHP test and fixture independent of upper packages and repository harnesses', function (): void {
    $root = dirname(__DIR__, 3);
    $violations = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/packages/core/tests', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (kernelTestDependencies((string) file_get_contents($file->getPathname())) as $dependency) {
            $violations[] = $file->getPathname().': '.$dependency;
        }
    }
    $composer = json_decode((string) file_get_contents($root.'/packages/core/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($violations)->toBe([])
        ->and(array_filter(array_keys($composer['require-dev']), static fn (string $name): bool => str_starts_with($name, 'axioma-studio/')))->toBe([])
        ->and($composer)->not->toHaveKey('repositories');
});

it('rejects aliased upper imports, string classes, includes and repository subprocess paths', function (string $source): void {
    expect(kernelTestDependencies('<?php '.$source))->not->toBe([]);
})->with([
    'alias' => ['use Vaulter\Media\Thing as Alias; new Alias;'],
    'group import' => ['use Vaulter\Drive\{Node, Handle};'],
    'class string' => ["class_exists('Vaulter\\\\Documents\\\\Provider');"],
    'integration' => ['new \Vaulter\Integration\Tests\TestCase;'],
    'include' => ["require dirname(__DIR__, 4).'/scripts/inventory/bootstrap.php';"],
    'sibling fixture' => ["file_get_contents('../drive/tests/Fixture.php');"],
    'root subprocess' => ["proc_open(['php', base_path('scripts/inventory/inventory.php')], [], \$pipes);"],
    'package fixture' => ["file_get_contents('packages/core/tests/Fixture.php');"],
]);

it('allows kernel-local fixtures and ignores dependencies mentioned only in comments', function (): void {
    expect(kernelTestDependencies("<?php /* Vaulter\\Drive\\Node */ use Vaulter\\Tests\\Fixtures\\HostKeyUser; require __DIR__.'/../../config/vaulter.php';"))->toBe([]);
});

it('rejects implicit upper module and table lookups but allows inert negative literals', function (string $source): void {
    expect(kernelTestDependencies('<?php '.$source))->not->toBe([]);
})->with([
    'module identity' => ["new ModuleId('drive');"],
    'schema entity owner' => ["app(ModuleSchemas::class)->entitiesOf('media');"],
    'runtime operation' => ["\$rows['drive.nodes.rename'];"],
    'drive table' => ["Schema::hasTable('v_drives');"],
    'published config' => ["config_path('vaulter-media.php');"],
    'load namespace' => ['use Vaulter\\Load\\Observability\\LoadStateMetrics;'],
]);

it('keeps a synthetic module and negative dependency strings usable without upper modules', function (): void {
    expect(kernelTestDependencies("<?php new ModuleId('plugin'); expect('drive.nodes.rename')->toBeString(); \$fixture = ['table' => 'v_drives', 'module' => 'media']; \$config->table('node_grants'); config()->set('vaulter-drive.access.permission_driver', 'database');"))->toBe([]);
});
