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
        if (preg_match('~Vaulter\\\\(?:Media|Drive|Documents|Webhooks|Collaboration|Azgard|Filament|Integration)(?:\\\\|$)|axioma-studio/vaulter-(?:media|drive|documents|webhooks|collaboration|azgard|filament)|(?:^|[/\\s])scripts/|tests/Integration|packages/[^/]+/tests|(?:\.\./)+(?:media|drive|documents|webhooks|collaboration|azgard|filament)/~', $value) === 1) {
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
