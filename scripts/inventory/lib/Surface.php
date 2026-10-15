<?php

declare(strict_types=1);

namespace Vaulter\Inventory;

use FilesystemIterator;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * The supported surface of D27, read from the attributes of the code: every type marked `#[Api]` or `#[Spi]` with
 * the public and protected members that are not `#[Internal]` (names of parameters count — named arguments are API),
 * and the I13 findings the same reading yields. The manifest is a function of the sources alone: sorted, no paths,
 * no line numbers.
 *
 * @phpstan-type Member array{group: int, name: string, line: string, types: list<string>, kind: string}
 * @phpstan-type SurfaceType array{fqcn: string, file: string, package: string, surface: string, since: string, header: string, doc: list<string>, members: list<Member>}
 */
final class Surface
{
    public const SNAPSHOT = 'packages/%s/tests/Arch/__snapshots__/surface.txt';

    public const INVARIANT = 'I13';

    private const API = 'Vaulter\Attributes\Api';

    private const SPI = 'Vaulter\Attributes\Spi';

    private const INTERNAL = 'Vaulter\Attributes\Internal';

    private const SPI_KIND = 'Vaulter\Attributes\SpiKind';

    public static function snapshotPath(string $package): string
    {
        return sprintf(self::SNAPSHOT, $package);
    }

    /**
     * Every type of packages/<p>/src with its surface, members and referenced types.
     *
     * @return list<SurfaceType>
     */
    public static function read(Repo $repo): array
    {
        $sources = [];
        foreach (SourceIndex::PACKAGES as $package) {
            $dir = $repo->path("packages/{$package}/src");
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $relative = substr($file->getPathname(), strlen($repo->root()) + 1);
                    $sources[$relative] = $repo->read($relative);
                }
            }
        }

        return self::fromSources($sources);
    }

    /**
     * @param  array<string, string> $sources repo-relative path (packages/<p>/src/...) => PHP code
     * @return list<SurfaceType>
     */
    public static function fromSources(array $sources): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $types = [];
        ksort($sources, SORT_STRING);
        foreach ($sources as $relative => $code) {
            $package = SourceIndex::packageOfFile($relative);
            if ($package === null) {
                continue;
            }
            try {
                $ast = $parser->parse($code) ?? [];
            } catch (Throwable $e) {
                throw new InventoryError("{$relative}: {$e->getMessage()}", 0, $e);
            }
            $ast = (new NodeTraverser(new NameResolver))->traverse($ast);
            foreach (self::classLikes($ast) as $node) {
                $types[] = self::type($node, $relative, $package);
            }
        }
        usort($types, static fn (array $a, array $b): int => strcmp($a['fqcn'], $b['fqcn']));

        return $types;
    }

    /**
     * One manifest per package: the Api and Spi types of the package.
     *
     * @param  list<SurfaceType>     $types
     * @return array<string, string>
     */
    public static function manifests(array $types): array
    {
        $manifests = [];
        foreach (SourceIndex::PACKAGES as $package) {
            $lines = [
                "# Surface manifest of the package `{$package}` (D27): the types marked #[Api] and #[Spi] with their public and protected members that are not #[Internal].",
                '# Generated: scripts/php84 php scripts/inventory/inventory.php surface --write. Do not edit by hand.',
            ];
            foreach ($types as $type) {
                if ($type['package'] !== $package || !in_array($type['surface'], ['Api', 'Spi:Implement', 'Spi:Call'], true)) {
                    continue;
                }
                $lines[] = '';
                $lines[] = $type['header'];
                foreach ($type['doc'] as $doc) {
                    $lines[] = '  '.$doc;
                }
                $members = $type['members'];
                usort($members, static fn (array $a, array $b): int => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);
                foreach ($members as $member) {
                    if ($member['line'] !== '') { // the signature members only carry the referenced types for I13
                        $lines[] = '  '.$member['line'];
                    }
                }
            }
            $manifests[$package] = implode("\n", $lines)."\n";
        }

        return $manifests;
    }

    /**
     * I13 (report mode until P7): a type without a surface attribute, and a supported signature that names a type of
     * Vaulter the host must not see (an `#[Internal]` type or an Eloquent model of the domain).
     *
     * @param  list<SurfaceType>                                                                                                                                             $types
     * @return list<array{invariant: string, view: string, file: string, symbol: string, kind: string, target: string, target_owner: string, count: int, category?: string}>
     */
    public static function violations(array $types, SourceIndex $index): array
    {
        $by = [];
        foreach ($types as $type) {
            $by[$type['fqcn']] = $type;
        }

        $found = [];
        foreach ($types as $type) {
            if ($type['surface'] === 'undecided') {
                $found[] = ['invariant' => self::INVARIANT, 'view' => 'unclassified', 'file' => $type['file'], 'symbol' => $type['fqcn'], 'kind' => 'no-surface-attribute', 'target' => $type['fqcn'], 'target_owner' => $type['package'], 'count' => 1, 'category' => 'unclassified'];

                continue;
            }
            if ($type['surface'] === 'Internal') {
                continue;
            }
            foreach ($type['members'] as $member) {
                foreach ($member['types'] as $target) {
                    $category = match (true) {
                        !isset($by[$target]) => null,
                        $by[$target]['surface'] === 'Internal' => 'internal-type',
                        $index->isEloquent($target) => 'domain-model',
                        default => null,
                    };
                    if ($category === null) {
                        continue;
                    }
                    $found[] = ['invariant' => self::INVARIANT, 'view' => 'signature', 'file' => $type['file'], 'symbol' => $type['fqcn'].'::'.$member['name'], 'kind' => $member['kind'], 'target' => $target, 'target_owner' => $by[$target]['package'], 'count' => 1, 'category' => $category];
                }
            }
        }

        $merged = [];
        foreach ($found as $violation) {
            $key = implode('|', [$violation['view'], $violation['file'], $violation['symbol'], $violation['kind'], $violation['target']]);
            if (isset($merged[$key])) {
                $merged[$key]['count']++;
            } else {
                $merged[$key] = $violation;
            }
        }
        ksort($merged, SORT_STRING);

        return array_values($merged);
    }

    /**
     * @param  array<Node>          $nodes
     * @return list<Stmt\ClassLike>
     */
    private static function classLikes(array $nodes): array
    {
        $found = [];
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                array_push($found, ...self::classLikes($node->stmts));
            } elseif ($node instanceof Stmt\ClassLike && $node->name !== null) {
                $found[] = $node;
            }
        }

        return $found;
    }

    /** @return SurfaceType */
    private static function type(Stmt\ClassLike $node, string $file, string $package): array
    {
        $fqcn = ($node->namespacedName ?? $node->name)?->toString() ?? '';
        $surfaces = [];
        $since = '1.0';
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = $attribute->name->toString();
                if (!in_array($name, [self::API, self::SPI, self::INTERNAL], true)) {
                    continue;
                }
                $surfaces[] = match ($name) {
                    self::API => 'Api',
                    self::INTERNAL => 'Internal',
                    default => 'Spi:'.self::spiKind($attribute),
                };
                if ($name !== self::INTERNAL) {
                    $since = self::sinceOf($attribute, $name === self::SPI ? 1 : 0) ?? $since;
                }
            }
        }
        $surface = count($surfaces) === 1 ? $surfaces[0] : 'undecided';
        $printer = new Standard;

        $members = [];
        foreach ($node->stmts as $stmt) {
            array_push($members, ...self::members($stmt, $printer));
        }

        return [
            'fqcn' => $fqcn,
            'file' => $file,
            'package' => $package,
            'surface' => $surface,
            'since' => $since,
            'header' => self::header($node, $fqcn, $surface, $since),
            'doc' => self::methodDoc($node),
            'members' => $members,
        ];
    }

    private static function header(Stmt\ClassLike $node, string $fqcn, string $surface, string $since): string
    {
        $label = match ($surface) {
            'Api' => 'api',
            'Spi:Implement' => 'spi:implement',
            'Spi:Call' => 'spi:call',
            default => strtolower($surface),
        };
        $keyword = match (true) {
            $node instanceof Stmt\Interface_ => 'interface',
            $node instanceof Stmt\Trait_ => 'trait',
            $node instanceof Stmt\Enum_ => 'enum',
            default => 'class',
        };
        $modifiers = [];
        if ($node instanceof Stmt\Class_) {
            $node->isAbstract() && $modifiers[] = 'abstract';
            $node->isFinal() && $modifiers[] = 'final';
            $node->isReadonly() && $modifiers[] = 'readonly';
        }
        $header = "{$fqcn} {$label} since {$since} ".implode(' ', [...$modifiers, $keyword]);
        if ($node instanceof Stmt\Enum_ && $node->scalarType !== null) {
            $header .= ': '.$node->scalarType->toString();
        }
        $extends = match (true) {
            $node instanceof Stmt\Class_ => $node->extends === null ? [] : [$node->extends->toString()],
            $node instanceof Stmt\Interface_ => array_map(static fn (Name $n): string => $n->toString(), $node->extends),
            default => [],
        };
        $implements = $node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_ ? array_map(static fn (Name $n): string => $n->toString(), $node->implements) : [];
        $traits = [];
        foreach ($node->getTraitUses() as $use) {
            foreach ($use->traits as $trait) {
                $traits[] = $trait->toString();
            }
        }
        sort($extends, SORT_STRING);
        sort($implements, SORT_STRING);
        sort($traits, SORT_STRING);
        foreach (['extends' => $extends, 'implements' => $implements, 'uses' => $traits] as $word => $names) {
            if ($names !== []) {
                $header .= " {$word} ".implode(', ', $names);
            }
        }

        return $header;
    }

    /** @return list<string> the `@method` lines of the class docblock (a facade states its surface there) */
    private static function methodDoc(Stmt\ClassLike $node): array
    {
        $doc = $node->getDocComment();
        if ($doc === null) {
            return [];
        }
        $lines = [];
        foreach (preg_split('/\R/', $doc->getText()) ?: [] as $line) {
            $line = (string) preg_replace(['#^\s*(/\*\*|\*)\s?#', '#\s*\*/\s*$#'], '', $line);
            if (preg_match('/^\s*(@method\b.*)$/', $line, $m) === 1) {
                $lines[] = trim((string) preg_replace('/\s+/', ' ', $m[1]));
            }
        }
        sort($lines, SORT_STRING);

        return $lines;
    }

    /** @return list<Member> */
    private static function members(Stmt $stmt, Standard $printer): array
    {
        $members = [];
        if ($stmt instanceof Stmt\ClassConst && !self::internal($stmt->attrGroups) && !$stmt->isPrivate()) {
            foreach ($stmt->consts as $const) {
                $members[] = [
                    'group' => 0,
                    'name' => $const->name->toString(),
                    'line' => self::visibility($stmt->flags).($stmt->isFinal() ? ' final' : '').' const '.($stmt->type === null ? '' : self::typeString($stmt->type).' ').$const->name->toString().' = '.self::expr($printer, $const->value),
                    'types' => self::typeNames($stmt->type),
                    'kind' => 'constant',
                ];
            }
        } elseif ($stmt instanceof Stmt\EnumCase) {
            $members[] = [
                'group' => 1,
                'name' => $stmt->name->toString(),
                'line' => 'case '.$stmt->name->toString().($stmt->expr === null ? '' : ' = '.self::expr($printer, $stmt->expr)),
                'types' => [],
                'kind' => 'case',
            ];
        } elseif ($stmt instanceof Stmt\Property && !self::internal($stmt->attrGroups) && !$stmt->isPrivate()) {
            foreach ($stmt->props as $property) {
                $members[] = [
                    'group' => 2,
                    'name' => '$'.$property->name->toString(),
                    'line' => self::visibility($stmt->flags).($stmt->isStatic() ? ' static' : '').($stmt->isReadonly() ? ' readonly' : '').' '.($stmt->type === null ? '' : self::typeString($stmt->type).' ').'$'.$property->name->toString().($property->default === null ? '' : ' = '.self::expr($printer, $property->default)),
                    'types' => self::typeNames($stmt->type),
                    'kind' => 'property',
                ];
            }
        } elseif ($stmt instanceof Stmt\ClassMethod) {
            if ($stmt->name->toString() === '__construct') {
                foreach ($stmt->params as $param) {
                    // A promoted public or protected parameter is a property of the type, whatever happens to the constructor.
                    if ($param->flags !== 0 && ($param->flags & Modifiers::PRIVATE) === 0 && !self::internal($param->attrGroups)) {
                        $members[] = [
                            'group' => 2,
                            'name' => '$'.self::paramName($param),
                            'line' => ($param->flags & Modifiers::PROTECTED ? 'protected' : 'public').($param->flags & Modifiers::READONLY ? ' readonly' : '').' '.($param->type === null ? '' : self::typeString($param->type).' ').'$'.self::paramName($param),
                            'types' => self::typeNames($param->type),
                            'kind' => 'property',
                        ];
                    }
                }
            }
            if (!$stmt->isPrivate() && !self::internal($stmt->attrGroups)) {
                $params = [];
                $types = [];
                foreach ($stmt->params as $param) {
                    $params[] = ($param->type === null ? '' : self::typeString($param->type).' ').($param->byRef ? '&' : '').($param->variadic ? '...' : '').'$'.self::paramName($param).($param->default === null ? '' : ' = '.self::expr($printer, $param->default));
                    array_push($types, ...array_map(static fn (string $t): string => 'param:'.$t, self::typeNames($param->type)));
                }
                array_push($types, ...array_map(static fn (string $t): string => 'return:'.$t, self::typeNames($stmt->returnType)));
                $name = $stmt->name->toString();
                foreach (self::split($types) as $kind => $names) {
                    foreach ($names as $target) {
                        $members[] = ['group' => 4, 'name' => $name.'()', 'line' => '', 'types' => [$target], 'kind' => $kind];
                    }
                }
                // The member line itself carries no types: the signature members above carry them for I13, merged by name.
                $members[] = [
                    'group' => 3,
                    'name' => $name,
                    'line' => self::visibility($stmt->flags).($stmt->isStatic() ? ' static' : '').($stmt->isAbstract() ? ' abstract' : '').($stmt->isFinal() ? ' final' : '').' function '.$name.'('.implode(', ', $params).')'.($stmt->returnType === null ? '' : ': '.self::typeString($stmt->returnType)),
                    'types' => [],
                    'kind' => 'method',
                ];
            }
        }

        return $members;
    }

    /**
     * @param  list<string>                $tagged `param:Type` / `return:Type`
     * @return array<string, list<string>>
     */
    private static function split(array $tagged): array
    {
        $by = [];
        foreach ($tagged as $item) {
            [$kind, $name] = explode(':', $item, 2);
            $by[$kind][] = $name;
        }

        return $by;
    }

    private static function paramName(Node\Param $param): string
    {
        return $param->var instanceof Expr\Variable && is_string($param->var->name) ? $param->var->name : '';
    }

    private static function visibility(int $flags): string
    {
        return ($flags & Modifiers::PROTECTED) !== 0 ? 'protected' : 'public';
    }

    /** @param list<Node\AttributeGroup> $groups */
    private static function internal(array $groups): bool
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->toString() === self::INTERNAL) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function expr(Standard $printer, Expr $expr): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $printer->prettyPrintExpr($expr)));
    }

    private static function typeString(?Node $type): string
    {
        return match (true) {
            $type === null => '',
            $type instanceof Node\Identifier => $type->toString(),
            $type instanceof Name => $type->toString(),
            $type instanceof Node\NullableType => '?'.self::typeString($type->type),
            $type instanceof Node\UnionType => self::joined($type->types, '|'),
            $type instanceof Node\IntersectionType => self::joined($type->types, '&'),
            default => '',
        };
    }

    /** @param array<Node> $types */
    private static function joined(array $types, string $glue): string
    {
        $parts = array_map(self::typeString(...), $types);
        sort($parts, SORT_STRING);

        return implode($glue, $parts);
    }

    /** @return list<string> the class names a type names (builtin names are not classes) */
    private static function typeNames(?Node $type): array
    {
        return match (true) {
            $type === null, $type instanceof Node\Identifier => [],
            $type instanceof Name => in_array(strtolower($type->toString()), ['self', 'static', 'parent'], true) ? [] : [$type->toString()],
            $type instanceof Node\NullableType => self::typeNames($type->type),
            $type instanceof Node\UnionType, $type instanceof Node\IntersectionType => array_merge(...array_map(self::typeNames(...), $type->types)),
            default => [],
        };
    }

    private static function sinceOf(Node\Attribute $attribute, int $position): ?string
    {
        foreach ($attribute->args as $i => $argument) {
            if (($argument->name === null && $i === $position) || $argument->name?->toString() === 'since') {
                return $argument->value instanceof Node\Scalar\String_ ? $argument->value->value : null;
            }
        }

        return null;
    }

    private static function spiKind(Node\Attribute $attribute): string
    {
        foreach ($attribute->args as $i => $argument) {
            if (($argument->name === null && $i === 0) || $argument->name?->toString() === 'kind') {
                $value = $argument->value;
                if ($value instanceof Expr\ClassConstFetch && $value->class instanceof Name && $value->class->toString() === self::SPI_KIND && $value->name instanceof Node\Identifier) {
                    return $value->name->toString();
                }
            }
        }

        return 'Implement';
    }
}
