<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/** @return list<string> */
function forbiddenBoundaryDependencies(string $source): array
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
    $collector = new class extends NodeVisitorAbstract
    {
        /** @var list<string> */
        public array $offenders = [];

        public function enterNode(Node $node): null
        {
            $names = [];
            if ($node instanceof Node\Name) {
                $names[] = $node->toString();
            } elseif ($node instanceof Node\Scalar\String_) {
                $names[] = ltrim($node->value, '\\');
            } elseif ($node instanceof Node\Stmt\GroupUse) {
                foreach ($node->uses as $use) {
                    $names[] = $node->prefix->toString().'\\'.$use->name->toString();
                }
            }
            foreach ($names as $name) {
                if (preg_match('/^Vaulter\\\\(?:[A-Za-z_][A-Za-z0-9_]*\\\\)?(?:Actions|Repositories)(?:\\\\|$)/i', $name) === 1) {
                    $this->offenders[] = $name;
                }
            }

            return null;
        }
    };
    $resolved = (new NodeTraverser(new NameResolver))->traverse($ast ?? []);
    (new NodeTraverser($collector))->traverse($resolved);

    return array_values(array_unique($collector->offenders));
}

it('keeps migrated HTTP and Filament boundaries on Operations including aliases and literal class calls', function (): void {
    $root = dirname(__DIR__, 3);
    $paths = [
        'drive/src/Http/Controllers/NodeController.php',
        'drive/src/Http/Controllers/DownloadController.php',
        'core/src/Http/Controllers/Access/GrantController.php',
        'core/src/Http/Controllers/Sharing/ShareController.php',
        'core/src/Http/Controllers/Activity/ActivityController.php',
        'filament/src/Resources/NodeResource.php',
        'filament/src/Resources/NodeGrantResource.php',
        'filament/src/Resources/NodeShareResource.php',
        'filament/src/Resources/ActivityResource.php',
    ];
    foreach ($paths as $path) {
        $source = file_get_contents($root.'/'.$path);
        expect($source)->toBeString();
        expect(forbiddenBoundaryDependencies($source))->toBe([], $path);
    }
});

it('detects a repository alias and grouped Action import while ignoring commentary', function (): void {
    expect(forbiddenBoundaryDependencies(<<<'PHP_CODE'
<?php
use Vaulter\Repositories\Drive\NodeReadRepository as ReadNodes;
use Vaulter\Drive\Actions\Nodes\{RenameNodeAction as Rename};
app(ReadNodes::class);
Rename::execute();
// Vaulter\Repositories\Drive\AnotherRepository is only a comment.
PHP_CODE))->toContain('Vaulter\Repositories\Drive\NodeReadRepository', 'Vaulter\Drive\Actions\Nodes\RenameNodeAction')
        ->not->toContain('Vaulter\Repositories\Drive\AnotherRepository');
});

it('detects both fully qualified class calls and a literal container dependency', function (): void {
    expect(forbiddenBoundaryDependencies(<<<'PHP_CODE'
<?php
\Vaulter\Drive\Actions\Nodes\RenameNodeAction::execute();
app('Vaulter\\Repositories\\Drive\\NodeReadRepository');
PHP_CODE))->toContain('Vaulter\Drive\Actions\Nodes\RenameNodeAction', 'Vaulter\Repositories\Drive\NodeReadRepository');
});
