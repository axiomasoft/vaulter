<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * PLAN2.P4.8 (ADR 0020 §3, audit F13): the documents HTTP layer and the facade reach
 * documents only through DocumentOperations/CommentOperations. They never import an
 * Action or a Repository — of any Vaulter package — by use statement, alias, fully
 * qualified call or class-name string.
 *
 * @return list<string>
 */
function documentsBoundaryOffenders(string $source): array
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

it('keeps the documents HTTP layer and facade on Operations', function (): void {
    $src = dirname(__DIR__, 2).'/src';
    $offenders = [];
    foreach ((new Finder)->files()->in([$src.'/Http', $src.'/Facades'])->name('*.php') as $file) {
        $found = documentsBoundaryOffenders($file->getContents());
        if ($found !== []) {
            $offenders[substr($file->getPathname(), strlen($src) + 1)] = $found;
        }
    }

    expect($offenders)->toBe([], 'Controllers, requests, resources and the facade must call Operations, not Actions or Repositories.');
});

it('reaches the operations from the facade manager', function (): void {
    $manager = file_get_contents(dirname(__DIR__, 2).'/src/DocumentsManager.php');

    expect($manager)->toBeString()
        ->toContain('extension(DocumentOperations::class)')
        ->toContain('extension(CommentOperations::class)');
});

it('detects an aliased repository, a grouped Action import and a class-name string', function (): void {
    expect(documentsBoundaryOffenders(<<<'PHP_CODE'
<?php
use Vaulter\Drive\Repositories\NodeReadRepository as ReadNodes;
use Vaulter\Documents\Actions\Comments\{AddCommentAction as Add};
app(ReadNodes::class);
app('Vaulter\\Documents\\Repositories\\Documents\\DocumentReadRepository');
// Vaulter\Documents\Actions\Documents\CreateDocumentAction is only a comment.
PHP_CODE))->toContain(
        'Vaulter\Drive\Repositories\NodeReadRepository',
        'Vaulter\Documents\Actions\Comments\AddCommentAction',
        'Vaulter\Documents\Repositories\Documents\DocumentReadRepository',
    )->not->toContain('Vaulter\Documents\Actions\Documents\CreateDocumentAction');
});
