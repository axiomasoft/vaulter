<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

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

/**
 * ADR 0020 §3 as a ratchet (audit F13): no HTTP controller or Filament class imports Actions or
 * Repositories. The files below predate the migration to Operations; the list can only shrink,
 * and a file that is clean again must be removed from it. Documents controllers leave in P4.
 */
it('keeps every HTTP and Filament boundary on Operations except the shrinking exemption list', function (): void {
    $root = dirname(__DIR__, 3);
    $exempt = [
        'core/src/Http/Controllers/Sharing/PublicShareController.php',
        'core/src/Http/Controllers/Webhooks/WebhookEndpointsController.php',
        'core/src/Http/Support/HttpResourceResolver.php',
        'documents/src/Http/Controllers/Collaboration/NodeCollaborationController.php',
        'documents/src/Http/Controllers/Comments/NodeCommentController.php',
        'documents/src/Http/Controllers/Documents/DocumentNodeController.php',
        'drive/src/Http/Controllers/Drives/ShowDriveController.php',
        'drive/src/Http/Controllers/Uploads/S3MultipartProtocolController.php',
        'drive/src/Http/Controllers/Uploads/TusProtocolController.php',
        'drive/src/Http/Resources/DriveResource.php',
        'filament/src/Support/PanelAccessScope.php',
    ];
    $offenders = [];
    $directories = array_filter([
        $root.'/core/src/Http', $root.'/drive/src/Http', $root.'/documents/src/Http', $root.'/filament/src',
    ], 'is_dir');
    foreach ((new Finder)->files()->in($directories)->name('*.php') as $file) {
        if (forbiddenBoundaryDependencies($file->getContents()) !== []) {
            $offenders[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($offenders);

    expect(array_values(array_diff($offenders, $exempt)))->toBe([], 'New controller/Filament code must call Operations, not Actions or Repositories.')
        ->and(array_values(array_filter($exempt, static fn (string $path): bool => is_file($root.'/'.$path) && !in_array($path, $offenders, true))))
        ->toBe([], 'These files no longer import Actions/Repositories: remove them from the exemption list.');
});
