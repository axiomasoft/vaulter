<?php

declare(strict_types=1);

namespace Vaulter\Tests\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Bounded syntax guard, independent of class_exists() and Pest's class inventory.
 * Comments and ordinary data strings are not dependencies. Literal class strings
 * are checked only at conventional container/reflection/class-consuming calls.
 * Variables, computed callables and runtime class-string propagation need review;
 * this guard intentionally does not attempt PHP data-flow or execute scanned files.
 */
final class ConfigurationArchitectureGuard
{
    private const PACKAGES = ['core', 'drive', 'documents', 'media', 'filament', 'azgard'];

    private const RETIRED_CLASSES = [
        'vaulter\\support\\config',
        'vaulter\\support\\gateconfig',
        'vaulter\\support\\spatieconfig',
        'vaulter\\drive\\support\\uploads\\uploadmodels',
        'vaulter\\drive\\uploads\\uploadmodels',
        'vaulter\\documents\\documentsmodels',
    ];

    /** @return list<string> */
    public function scanPackages(string $packagesDirectory): array
    {
        $violations = [];
        foreach (self::PACKAGES as $package) {
            $owner = rtrim($packagesDirectory, '/').'/'.$package;
            foreach (['src', 'tests', 'config', 'database', 'routes'] as $scope) {
                $directory = $owner.'/'.$scope;
                $required = in_array($scope, ['src', 'tests', 'config'], true);
                if (!file_exists($directory)) {
                    if ($required) {
                        $violations[] = $directory.':1: cannot inventory required package scope';
                    }

                    continue;
                }

                try {
                    if (!is_dir($directory) || !is_readable($directory)) {
                        throw new RuntimeException('scope must be a readable directory');
                    }

                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST,
                    );
                    $phpFiles = 0;
                    foreach ($files as $file) {
                        $path = $file->getPathname();
                        // RecursiveDirectoryIterator does not follow directory links.
                        // Fail closed rather than silently omitting their PHP files.
                        if ($file->isLink() && $file->isDir()) {
                            $violations[] = $path.':1: linked directory cannot be inventoried';

                            continue;
                        }
                        if ($file->isDir() || strtolower($file->getExtension()) !== 'php') {
                            continue;
                        }
                        $phpFiles++;
                        $contents = @file_get_contents($path);
                        if ($contents === false) {
                            $violations[] = $path.':1: cannot read PHP file';

                            continue;
                        }
                        $allowConfig = in_array($scope, ['tests', 'config'], true)
                            || ($scope === 'src' && str_starts_with($path, $owner.'/src/Configuration/'));
                        array_push($violations, ...$this->scanSource($contents, $path, $allowConfig));
                    }
                    if ($required && $phpFiles === 0) {
                        $violations[] = $directory.':1: required package scope contains no PHP files';
                    }
                } catch (Throwable $error) {
                    $violations[] = $directory.':1: inventory failed: '.$error->getMessage();
                }
            }
        }

        sort($violations);

        return array_values(array_unique($violations));
    }

    /** @return list<string> */
    public function scanSource(string $source, string $path, bool $allowConfig = false): array
    {
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
            $traverser = new NodeTraverser(new NameResolver);
            $nodes = $traverser->traverse($nodes);
        } catch (Throwable $error) {
            $line = $error instanceof Error ? max(1, $error->getStartLine()) : 1;

            return [$path.':'.$line.': cannot parse/resolve PHP: '.$error->getMessage()];
        }

        $violations = [];
        foreach ($nodes as $node) {
            $this->inspect($node, $path, $allowConfig, $violations);
        }

        return array_values(array_unique($violations));
    }

    /** @param list<string> $violations */
    private function inspect(Node $node, string $path, bool $allowConfig, array &$violations): void
    {
        if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
            foreach ($node->uses as $use) {
                if (($node->type | $use->type) !== Stmt\Use_::TYPE_NORMAL) {
                    continue;
                }
                $name = $node instanceof Stmt\GroupUse ? $node->prefix.'\\'.$use->name : (string) $use->name;
                $this->checkClass($name, $use, $path, $violations);
            }

            return;
        }

        if ($node instanceof Name\FullyQualified) {
            $this->checkClass((string) $node, $node, $path, $violations);
        }
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Interface_ || $node instanceof Stmt\Trait_ || $node instanceof Stmt\Enum_) {
            if ($node->namespacedName !== null) {
                $this->checkClass((string) $node->namespacedName, $node, $path, $violations);
            }
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $function = strtolower((string) $node->name);
            if (!$allowConfig && $function === 'config') {
                $violations[] = $path.':'.$node->getStartLine().': direct config() outside owning src/Configuration';
            }
            $arguments = match ($function) {
                'app', 'resolve', 'class_exists', 'interface_exists', 'trait_exists', 'enum_exists' => [0 => ['abstract', 'class']],
                'class_alias' => [0 => ['class'], 1 => ['alias']],
                'is_a', 'is_subclass_of' => [0 => ['object_or_class'], 1 => ['class']],
                default => [],
            };
            $this->checkArguments($node->args, $arguments, $path, $violations);
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier) {
            $arguments = match (strtolower((string) $node->name)) {
                'make', 'makewith', 'resolve' => [0 => ['abstract']],
                'bind', 'bindif', 'singleton', 'singletonif', 'scoped', 'scopedif' => [0 => ['abstract'], 1 => ['concrete']],
                'instance' => [0 => ['abstract']],
                default => [],
            };
            $this->checkArguments($node->args, $arguments, $path, $violations);
        }
        if ($node instanceof Expr\New_ && $node->class instanceof Name) {
            $arguments = match (strtolower((string) $node->class)) {
                'reflectionclass', 'reflectionmethod', 'reflectionproperty' => [0 => ['objectOrClass', 'class']],
                default => [],
            };
            $this->checkArguments($node->args, $arguments, $path, $violations);
        }
        if (($node instanceof Expr\New_ || $node instanceof Expr\StaticCall || $node instanceof Expr\StaticPropertyFetch || $node instanceof Expr\ClassConstFetch || $node instanceof Expr\Instanceof_) && $node->class instanceof Expr) {
            $this->checkLiteral($node->class, $path, $violations);
        }

        foreach ($node->getSubNodeNames() as $field) {
            // Namespace declarations and function/constant names are not class refs.
            if ($field === 'name' && ($node instanceof Stmt\Namespace_ || $node instanceof Expr\FuncCall || $node instanceof Expr\ConstFetch)) {
                continue;
            }
            $value = $node->{$field};
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->inspect($child, $path, $allowConfig, $violations);
                }
            }
        }
    }

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder> $args
     * @param array<int, list<string>>                 $positions
     * @param list<string>                             $violations
     */
    private function checkArguments(array $args, array $positions, string $path, array &$violations): void
    {
        foreach ($args as $position => $argument) {
            if (!$argument instanceof Node\Arg) {
                continue;
            }
            foreach ($positions as $index => $names) {
                if (($argument->name === null && $position === $index)
                    || ($argument->name !== null && in_array((string) $argument->name, $names, true))) {
                    $this->checkLiteral($argument->value, $path, $violations);
                }
            }
        }
    }

    /** @param list<string> $violations */
    private function checkLiteral(Expr $expression, string $path, array &$violations): void
    {
        $value = $this->literalString($expression);
        if ($value !== null) {
            $this->checkClass($value, $expression, $path, $violations);
        }
    }

    private function literalString(Expr $expression): ?string
    {
        if ($expression instanceof String_) {
            return $expression->value;
        }
        if ($expression instanceof Expr\BinaryOp\Concat) {
            $left = $this->literalString($expression->left);
            $right = $this->literalString($expression->right);

            return $left !== null && $right !== null ? $left.$right : null;
        }

        return null;
    }

    /** @param list<string> $violations */
    private function checkClass(string $name, Node $node, string $path, array &$violations): void
    {
        if (in_array(strtolower(ltrim($name, '\\')), self::RETIRED_CLASSES, true)) {
            $violations[] = $path.':'.$node->getStartLine().': retired configuration helper '.$name;
        }
    }
}
