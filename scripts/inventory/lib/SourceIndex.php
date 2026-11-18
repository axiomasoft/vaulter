<?php

declare(strict_types=1);

namespace Vaulter\Inventory;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * One parse of every covered source file: declarations and references, the single reading of the code that the
 * type-map, the identities and the scans share.
 *
 * @phpstan-type Method array{name: string, visibility: string, static: bool}
 * @phpstan-type Declaration array{fqcn: string, kind: string, file: string, package: ?string, extends: list<string>, implements: list<string>, traits: list<string>, attributes: list<string>, spi_kind: ?string, methods: list<Method>}
 * @phpstan-type Reference array{file: string, symbol: string, kind: string, target: string}
 */
final class SourceIndex
{
    /** The code of a package is packages/<p>/{src,database,config,routes}/**\/*.php; which packages: Repo::sourcePackages(). */
    public const ROOTS = ['src', 'database', 'config', 'routes'];

    public const ELOQUENT_BASES = [
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Database\Eloquent\Relations\Pivot',
        'Illuminate\Database\Eloquent\Relations\MorphPivot',
        'Illuminate\Foundation\Auth\User',
    ];

    /** @var array<string, self> */
    private static array $cache = [];

    /** @var array<string, int> fqcn => index into (the first declaration wins) */
    private array $byFqcn = [];

    /** @var array<string, string> longest prefix first */
    private array $psr4;

    /**
     * @param list<Declaration>     $declarations
     * @param list<Reference>       $references
     * @param list<Reference>       $dynamic
     * @param array<string, string> $psr4         prefix => package
     */
    private function __construct(private readonly array $declarations, private readonly array $references, private readonly array $dynamic, array $psr4)
    {
        uksort($psr4, static fn (string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
        $this->psr4 = $psr4;
        foreach ($declarations as $i => $declaration) {
            $this->byFqcn[$declaration['fqcn']] ??= $i;
        }
    }

    /**
     * @param list<string>|null $tables logical table names (without v_); default: the core migrations' tables
     */
    public static function forRepo(Repo $repo, ?array $tables = null): self
    {
        $tables ??= self::migrationTables($repo);
        $key = $repo->root().'|'.implode(',', $tables);

        return self::$cache[$key] ??= self::fromSources(self::readSources($repo), $repo->psr4(), $tables);
    }

    /**
     * @param array<string, string>      $sources repo-relative path => PHP code
     * @param array<string, string>|null $psr4    prefix => package; default: the packages fixture of this repository
     * @param list<string>               $tables  logical table names (without v_)
     */
    public static function fromSources(array $sources, ?array $psr4 = null, array $tables = []): self
    {
        $psr4 ??= self::defaultPsr4();
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $declarations = [];
        $references = [];
        $dynamic = [];

        ksort($sources, SORT_STRING);
        foreach ($sources as $file => $code) {
            try {
                $ast = $parser->parse($code) ?? [];
            } catch (Throwable $e) {
                throw new InventoryError("{$file}: {$e->getMessage()}", 0, $e);
            }
            $traverser = new NodeTraverser(new NameResolver);
            $visitor = new IndexVisitor($file, self::packageOfFile($file), $tables);
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            array_push($declarations, ...$visitor->declarations);
            array_push($references, ...$visitor->references);
            array_push($dynamic, ...$visitor->dynamic);
        }

        return new self($declarations, $references, $dynamic, $psr4);
    }

    /** @return list<Declaration> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /** @return Declaration|null */
    public function declaration(string $fqcn): ?array
    {
        $i = $this->byFqcn[ltrim($fqcn, '\\')] ?? null;

        return $i === null ? null : $this->declarations[$i];
    }

    /** @return list<Reference> */
    public function references(?string $kind = null): array
    {
        return $kind === null ? $this->references : array_values(array_filter($this->references, static fn (array $r): bool => $r['kind'] === $kind));
    }

    /** @return list<Reference> `$class::x()`-style references whose class is not a name; findings, not scans */
    public function dynamicReferences(): array
    {
        return $this->dynamic;
    }

    /** Follows `extends` through the index; an external parent counts only by its name. */
    public function isEloquent(string $fqcn): bool
    {
        $current = ltrim($fqcn, '\\');
        $seen = [];
        while (!isset($seen[$current])) {
            if (in_array($current, self::ELOQUENT_BASES, true)) {
                return true;
            }
            $seen[$current] = true;
            $declaration = $this->declaration($current);
            if ($declaration === null || $declaration['kind'] === 'interface' || $declaration['extends'] === []) {
                return false;
            }
            $current = $declaration['extends'][0];
        }

        return false;
    }

    /** The package owning the longest PSR-4 prefix of the name; the not yet existing packages count too. */
    public function packageOf(string $fqcn): ?string
    {
        $name = ltrim($fqcn, '\\');
        foreach ($this->psr4 as $prefix => $package) {
            if (str_starts_with($name, $prefix)) {
                return $package;
            }
        }

        return null;
    }

    public static function packageOfFile(string $file): ?string
    {
        return preg_match('#^packages/([^/]+)/#', $file, $m) === 1 ? $m[1] : null;
    }

    /** @return array<string, string> */
    private static function defaultPsr4(): array
    {
        try {
            return Repo::discover()->psr4();
        } catch (InventoryError) {
            return [];
        }
    }

    /** @return list<string> */
    private static function migrationTables(Repo $repo): array
    {
        $tables = [];
        foreach (glob($repo->path('packages/*/database/migrations/*.php')) ?: [] as $migration) {
            if (preg_match('/create_v_(.+)_table\.php$/', basename($migration), $m) === 1) {
                $tables[] = $m[1];
            }
        }
        sort($tables, SORT_STRING);

        return $tables;
    }

    /** @return array<string, string> */
    private static function readSources(Repo $repo): array
    {
        $files = [];
        foreach ($repo->sourcePackages() as $package) {
            foreach (self::ROOTS as $root) {
                $dir = $repo->path("packages/{$package}/{$root}");
                if (!is_dir($dir)) {
                    continue;
                }
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                        $relative = substr($file->getPathname(), strlen($repo->root()) + 1);
                        $files[$relative] = $repo->read($relative);
                    }
                }
            }
        }

        return $files;
    }
}

/**
 * Collects the declarations and references of one file. Runs after NameResolver, so every class name it meets is
 * already fully qualified (a relative name in a namespace, an import, a group use — all resolved).
 *
 * @internal
 *
 * @phpstan-import-type Declaration from SourceIndex
 * @phpstan-import-type Reference from SourceIndex
 */
final class IndexVisitor extends NodeVisitorAbstract
{
    private const FQCN_STRING = '/^\\\\?Vaulter\\\\[A-Za-z0-9_\\\\]+$/';

    private const TABLE_CALLS = ['table', 'from', 'join', 'leftJoin', 'rightJoin', 'crossJoin'];

    private const TABLE_ARGS = ['table', 'entity', 'from'];

    /** Every static call on these classes takes the table as its first argument (`DB::table` is covered by TABLE_CALLS). */
    private const TABLE_CLASSES = ['Schema'];

    /** Named arguments that stand for the first (key/name) argument of a config/route call. */
    private const KEY_ARGS = ['key', 'name'];

    private const CONFIG_READS = ['get', 'has', 'string', 'integer', 'float', 'boolean', 'array'];

    private const PROBE_FUNCTIONS = ['class_exists', 'interface_exists', 'enum_exists', 'trait_exists', 'function_exists', 'extension_loaded'];

    private const PROBE_METHODS = ['bound', 'resolved'];

    /** @var list<Declaration> */
    public array $declarations = [];

    /** @var list<Reference> */
    public array $references = [];

    /** @var list<Reference> */
    public array $dynamic = [];

    /** @var list<Node> */
    private array $stack = [];

    /** @var list<?string> */
    private array $classes = [];

    /** @var list<string> */
    private array $methods = [];

    /** @var array<string, true> */
    private array $tables = [];

    private Standard $printer;

    /** @param  list<string>  $tables */
    public function __construct(private readonly string $file, private readonly ?string $package, array $tables)
    {
        foreach ($tables as $table) {
            $this->tables[$table] = true;
        }
        $this->printer = new Standard;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            $this->classes[] = $node->name === null ? ($this->currentClass() ?? '<file>').'@anonymous' : ($node->namespacedName ?? $node->name)->toString();
        } elseif ($node instanceof Stmt\ClassMethod) {
            $class = $this->currentClass();
            $this->methods[] = $class === null ? '<file>' : $class.'::'.$node->name->toString();
        }

        match (true) {
            $node instanceof Stmt\Use_ => $this->useItems($node->uses, null),
            $node instanceof Stmt\GroupUse => $this->useItems($node->uses, $node->prefix),
            $node instanceof Name\FullyQualified => $this->onName($node),
            $node instanceof String_ => $this->onString($node),
            default => null,
        };
        $this->onDynamicClass($node);

        $this->stack[] = $node;

        return null;
    }

    public function leaveNode(Node $node)
    {
        array_pop($this->stack);
        // Names below this node are resolved only once the traverser has entered them: read them on the way out.
        $call = $this->asCall($node);
        if ($call !== null) {
            $this->onCall($call);
        }
        if ($node instanceof Stmt\ClassLike) {
            $this->declare($node);
            array_pop($this->classes);
        } elseif ($node instanceof Stmt\ClassMethod) {
            array_pop($this->methods);
        }

        return null;
    }

    private function declare(Stmt\ClassLike $node): void
    {
        if ($node->name === null) {
            return;
        }
        $fqcn = ($node->namespacedName ?? $node->name)->toString();

        $extends = match (true) {
            $node instanceof Stmt\Class_ => $node->extends === null ? [] : [$node->extends->toString()],
            $node instanceof Stmt\Interface_ => array_map(static fn (Name $n): string => $n->toString(), $node->extends),
            default => [],
        };
        $implements = match (true) {
            $node instanceof Stmt\Class_, $node instanceof Stmt\Enum_ => array_map(static fn (Name $n): string => $n->toString(), $node->implements),
            default => [],
        };
        $traits = [];
        foreach ($node->getTraitUses() as $use) {
            foreach ($use->traits as $trait) {
                $traits[] = $trait->toString();
            }
        }
        $attributes = [];
        $spiKind = null;
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributes[] = $attribute->name->toString();
                if ($attribute->name->toString() === 'Packman\Attributes\Spi') {
                    $spiKind = self::spiKind($attribute);
                }
            }
        }
        $methods = [];
        foreach ($node->getMethods() as $method) {
            $methods[] = [
                'name' => $method->name->toString(),
                'visibility' => match (true) {
                    $method->isPrivate() => 'private',
                    $method->isProtected() => 'protected',
                    default => 'public',
                },
                'static' => $method->isStatic(),
            ];
        }

        $this->declarations[] = [
            'fqcn' => $fqcn,
            'kind' => match (true) {
                $node instanceof Stmt\Interface_ => 'interface',
                $node instanceof Stmt\Trait_ => 'trait',
                $node instanceof Stmt\Enum_ => 'enum',
                $node instanceof Stmt\Class_ && $node->isAbstract() => 'abstract-class',
                $node instanceof Stmt\Class_ && $node->isFinal() => 'final-class',
                default => 'class',
            },
            'file' => $this->file,
            'package' => $this->package,
            'extends' => $extends,
            'implements' => $implements,
            'traits' => $traits,
            'attributes' => $attributes,
            'spi_kind' => $spiKind,
            'methods' => $methods,
        ];
    }

    /** The case named by the `kind` argument of `#[Spi(SpiKind::Implement)]`; null when it is not a constant of SpiKind. */
    private static function spiKind(Node\Attribute $attribute): ?string
    {
        foreach ($attribute->args as $i => $argument) {
            if (($argument->name === null && $i === 0) || $argument->name?->toString() === 'kind') {
                $value = $argument->value;

                return $value instanceof Expr\ClassConstFetch && $value->name instanceof Node\Identifier && $value->class instanceof Name && $value->class->toString() === 'Packman\\Attributes\\SpiKind'
                    ? $value->name->toString()
                    : null;
            }
        }

        return null;
    }

    /** @param  list<Node\UseItem>  $items */
    private function useItems(array $items, ?Name $prefix): void
    {
        foreach ($items as $item) {
            $name = $prefix === null ? $item->name->toString() : $prefix->toString().'\\'.$item->name->toString();
            $this->add('use', $name);
        }
    }

    private function onName(Name\FullyQualified $name): void
    {
        $parent = $this->stack[count($this->stack) - 1] ?? null;
        if ($parent instanceof Expr\FuncCall && $parent->name === $name) {
            return;
        }
        if ($parent instanceof Expr\ConstFetch || $parent instanceof Node\UseItem) {
            return;
        }
        $this->add('name', $name->toString());
    }

    private function onString(String_ $node): void
    {
        $value = $node->value;
        if (preg_match(self::FQCN_STRING, $value) === 1) {
            $this->add('fqcn-string', ltrim($value, '\\'));
        }

        $parent = $this->stack[count($this->stack) - 1] ?? null;
        $argument = $parent instanceof Arg ? $parent : null;
        $call = $argument === null ? null : $this->asCall($this->stack[count($this->stack) - 2] ?? null);
        $first = $argument !== null && $argument->name === null && $call !== null && ($call->args[0] ?? null) === $argument;
        $key = $first || ($argument !== null && $argument->name !== null && in_array($argument->name->toString(), self::KEY_ARGS, true));

        if ($this->isTable($value)) {
            $argName = $argument?->name?->toString();
            if (($argName !== null && in_array($argName, self::TABLE_ARGS, true))
                || ($first && $this->isTableCall($call))
                || ($parent instanceof Node\PropertyItem && $parent->name->toString() === 'table')) {
                $this->add('table', $value);
            } elseif ($this->inTableClass() && $this->inLiteralList()) {
                $this->add('table-literal', $value);
            }
        }

        if (!$key || $call === null) {
            return;
        }
        if (str_starts_with($value, 'vaulter') && $this->isConfigCall($call)) {
            $this->add('config', $value);
        }
        if ($this->isRouteCall($call)) {
            $this->add('route', $value);
        }
    }

    private function onCall(Expr\FuncCall|Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call): void
    {
        [, $name] = $this->callParts($call);
        $isProbe = $call instanceof Expr\FuncCall
            ? $name !== null && in_array($name, self::PROBE_FUNCTIONS, true)
            : $name !== null && in_array($name, self::PROBE_METHODS, true);
        if (!$isProbe) {
            return;
        }
        $argument = $call->args[0] ?? null;
        $this->add('probe', $argument instanceof Arg ? $this->printer->prettyPrintExpr($argument->value) : '');
    }

    private function onDynamicClass(Node $node): void
    {
        $class = match (true) {
            $node instanceof Expr\StaticCall, $node instanceof Expr\ClassConstFetch, $node instanceof Expr\StaticPropertyFetch, $node instanceof Expr\New_, $node instanceof Expr\Instanceof_ => $node->class,
            default => null,
        };
        if ($class instanceof Expr) {
            $this->dynamic[] = ['file' => $this->file, 'symbol' => $this->symbol(), 'kind' => 'dynamic', 'target' => $this->printer->prettyPrintExpr($class)];
        }
    }

    private function asCall(?Node $node): Expr\FuncCall|Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall|null
    {
        return $node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall ? $node : null;
    }

    /** @return array{0: ?string, 1: ?string} [class last segment of a static call, called name] */
    private function callParts(Expr\FuncCall|Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call): array
    {
        $name = $call->name instanceof Name || $call->name instanceof Node\Identifier ? ($call->name instanceof Name ? $call->name->getLast() : $call->name->toString()) : null;
        $class = $call instanceof Expr\StaticCall && $call->class instanceof Name ? $call->class->getLast() : null;

        return [$class, $name];
    }

    private function isTable(string $value): bool
    {
        return isset($this->tables[$value]) || (str_starts_with($value, 'v_') && isset($this->tables[substr($value, 2)]));
    }

    private function isTableCall(Expr\FuncCall|Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call): bool
    {
        [$class, $name] = $this->callParts($call);

        return ($class !== null && in_array($class, self::TABLE_CLASSES, true)) || ($name !== null && in_array($name, self::TABLE_CALLS, true));
    }

    /** `config(`, `Config::get(`-style reads and `get`/`has` on something named like a config repository. */
    private function isConfigCall(Expr\FuncCall|Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call): bool
    {
        [$class, $name] = $this->callParts($call);

        return match (true) {
            $call instanceof Expr\FuncCall => $name === 'config',
            $call instanceof Expr\StaticCall => $class === 'Config' && $name !== null && in_array($name, self::CONFIG_READS, true),
            default => in_array($name, ['get', 'has'], true) && stripos($this->printer->prettyPrintExpr($call->var), 'config') !== false,
        };
    }

    private function isRouteCall(Expr\FuncCall|Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call): bool
    {
        [$class, $name] = $this->callParts($call);

        return match (true) {
            $call instanceof Expr\FuncCall => $name === 'route',
            $call instanceof Expr\StaticCall => ($class === 'Route' && in_array($name, ['has', 'name'], true)) || ($class === 'URL' && $name === 'route'),
            default => $name === 'name',
        };
    }

    /** Constants, array literals and match arms: the places where a bare table name is a declaration, not an argument. */
    private function inLiteralList(): bool
    {
        foreach ($this->stack as $node) {
            if ($node instanceof Expr\Array_ || $node instanceof Stmt\ClassConst || $node instanceof Node\MatchArm) {
                return true;
            }
        }

        return false;
    }

    private function inTableClass(): bool
    {
        $class = $this->currentClass();

        return $class !== null && preg_match('/(^|\\\\)(Schema|Repositories)\\\\/', $class) === 1;
    }

    private function currentClass(): ?string
    {
        return $this->classes === [] ? null : $this->classes[count($this->classes) - 1];
    }

    /** The enclosing `Class::method`, `Class` or `<file>`. */
    private function symbol(): string
    {
        if ($this->methods !== []) {
            return $this->methods[count($this->methods) - 1];
        }

        return $this->currentClass() ?? '<file>';
    }

    private function add(string $kind, string $target): void
    {
        $this->references[] = ['file' => $this->file, 'symbol' => $this->symbol(), 'kind' => $kind, 'target' => $target];
    }
}
