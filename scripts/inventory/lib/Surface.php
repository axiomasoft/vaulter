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
 * @phpstan-type Generic array{member: string, kind: string, target: string}
 * @phpstan-type SurfaceType array{fqcn: string, file: string, package: string, surface: string, since: string, header: string, doc: list<string>, members: list<Member>, facade?: array<string, list<string>>, generics?: list<Generic>, ancestors?: list<string>, structure?: array<string, mixed>}
 */
final class Surface
{
    public const SNAPSHOT = 'packages/%s/tests/Arch/__snapshots__/surface.txt';

    /** The machine view of the same reading (P6.2): versioned, keyed by FQCN and member key, read by the BC check. */
    public const STRUCTURE = 'packages/%s/tests/Arch/__snapshots__/surface.json';

    public const FORMAT = 'vaulter.surface';

    public const FORMAT_VERSION = 1;

    /** The docblock tags that state a contract (types, generics, throws); prose tags (@see, @link, @since…) are not read. */
    private const CONTRACT_TAGS = '/^@(?:phpstan-|psalm-)?(?:param|return|var|template[\w-]*|extends|implements|use|mixin|method|property[\w-]*|throws|readonly|immutable|pure|sealed|require-extends|require-implements|assert[\w-]*|param-out|self-out|this-out)\b/';

    public const INVARIANT = 'I13';

    private const API = 'Vaulter\Attributes\Api';

    private const SPI = 'Vaulter\Attributes\Spi';

    private const INTERNAL = 'Vaulter\Attributes\Internal';

    private const SPI_KIND = 'Vaulter\Attributes\SpiKind';

    /** The native ORM factory root: a supported type built on it hands the host the models it makes. */
    private const ORM_FACTORY = 'Illuminate\Database\Eloquent\Factories\Factory';

    /** The words of a PHPDoc type that are no class (PHPStan / Psalm pseudo types and keywords). */
    private const DOC_WORDS = ['int', 'integer', 'string', 'bool', 'boolean', 'float', 'double', 'array', 'list', 'iterable', 'callable', 'object', 'mixed', 'void', 'null', 'true', 'false', 'never', 'static', 'self', 'parent', 'resource', 'scalar', 'numeric', 'array-key', 'positive-int', 'negative-int', 'non-positive-int', 'non-negative-int', 'non-zero-int', 'non-empty-string', 'non-falsy-string', 'truthy-string', 'lowercase-string', 'non-empty-lowercase-string', 'non-empty-array', 'non-empty-list', 'class-string', 'interface-string', 'trait-string', 'enum-string', 'callable-string', 'numeric-string', 'literal-string', 'non-empty-literal-string', 'int-mask', 'int-mask-of', 'key-of', 'value-of', 'pure-callable', 'pure-closure', 'closed-resource', 'open-resource', 'empty', 'noreturn', 'never-return', 'never-returns', 'no-return', 'max', 'min', 'of', 'as', 'is', 'covariant', 'contravariant'];

    public static function snapshotPath(string $package): string
    {
        return sprintf(self::SNAPSHOT, $package);
    }

    public static function structurePath(string $package): string
    {
        return sprintf(self::STRUCTURE, $package);
    }

    /**
     * One structural JSON per package: its Api and Spi roots and every type of the package that is an ancestor (parent,
     * interface, trait) of a root anywhere — an Internal ancestor is no contract root but stays in the graph, because the
     * members a root inherits from it are part of the root's surface. A name outside the sources stays a bare name.
     *
     * @param  list<SurfaceType>     $types
     * @param  list<string>|null     $packages
     * @return array<string, string>
     */
    public static function structures(array $types, ?array $packages = null): array
    {
        $packages ??= array_values(array_unique(array_filter(array_column($types, 'package'))));
        sort($packages, SORT_STRING);
        $by = [];
        $folded = [];
        foreach ($types as $type) {
            $lower = strtolower($type['fqcn']);
            if (isset($folded[$lower])) {
                throw new InventoryError("duplicate type {$type['fqcn']} ({$folded[$lower]}, {$type['file']})");
            }
            $folded[$lower] = $type['file'];
            $by[$type['fqcn']] = $type;
        }

        $graph = [];
        $queue = [];
        foreach ($types as $type) {
            if (in_array($type['surface'], ['Api', 'Spi:Implement', 'Spi:Call'], true)) {
                $queue[] = $type['fqcn'];
            }
        }
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($graph[$current]) || !isset($by[$current])) {
                continue;
            }
            $graph[$current] = true;
            $structure = $by[$current]['structure'] ?? throw new InventoryError("{$current}: no structure read");
            array_push($queue, ...$structure['extends'], ...$structure['implements'], ...$structure['traits']);
        }

        $documents = [];
        foreach ($packages as $package) {
            $entries = [];
            foreach ($types as $type) {
                if ($type['package'] === $package && isset($graph[$type['fqcn']])) {
                    $entries[$type['fqcn']] = $type['structure'];
                }
            }
            ksort($entries, SORT_STRING);
            $document = ['format' => self::FORMAT, 'version' => self::FORMAT_VERSION, 'package' => $package, 'types' => (object) $entries];
            $documents[$package] = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
        }

        return $documents;
    }

    /**
     * Every type of packages/<p>/src with its surface, members and referenced types.
     *
     * @return list<SurfaceType>
     */
    public static function read(Repo $repo): array
    {
        $sources = [];
        foreach ($repo->sourcePackages() as $package) {
            // The code of a package: its src and the factories its hosts use in tests (database/factories).
            foreach (['src', 'database/factories'] as $root) {
                $dir = $repo->path("packages/{$package}/{$root}");
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
        }

        return self::fromSources($sources);
    }

    /**
     * @param  array<string, string> $sources repo-relative path (packages/<p>/src/... or database/factories/...) => PHP code
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
            foreach (self::classLikes($ast) as $found) {
                $types[] = self::type($found['node'], $relative, $package, $found['namespace'], $found['uses']);
            }
        }
        usort($types, static fn (array $a, array $b): int => strcmp($a['fqcn'], $b['fqcn']));

        return $types;
    }

    /**
     * One manifest per package: the Api and Spi types of the package.
     *
     * @param  list<SurfaceType>     $types
     * @param  list<string>|null     $packages the packages to write (Repo::sourcePackages()); default: those the types belong to
     * @return array<string, string>
     */
    public static function manifests(array $types, ?array $packages = null): array
    {
        $packages ??= array_values(array_unique(array_filter(array_column($types, 'package'))));
        sort($packages, SORT_STRING);
        $manifests = [];
        foreach ($packages as $package) {
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
                        $index->isEloquent($target) => 'domain-model',
                        $by[$target]['surface'] === 'Internal' => 'internal-type',
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
     * The I13 classifier (P6.3/U1): the gated signature findings of violations() with the source facts that classify each, and
     * the findings of the parts of a supported surface the gated reading does not see — the `@method` lines of a facade, the
     * generic types of the docblocks (`@return`, `@param`, `@var`, `@extends`/`@implements`/`@use`), the members a supported
     * type inherits from an Internal ancestor, and a supported type built on the native ORM factory. A name the classifier
     * cannot resolve (a Vaulter type it does not know, an ancestor outside the sources) is `review`, never a pass. No category
     * is exempted: an Eloquent model is a finding wherever it appears, and `host-input` is only ever granted by an allowlist.
     *
     * @param  list<SurfaceType>                                                                                                                                                                                                    $types
     * @return array{signature: list<array<string, mixed>>, extended: list<array{invariant: string, view: string, file: string, symbol: string, kind: string, target: string, target_owner: string, count: int, category: string}>}
     */
    public static function classify(array $types, SourceIndex $index): array
    {
        $by = [];
        foreach ($types as $type) {
            $by[$type['fqcn']] = $type;
        }

        $signature = [];
        foreach (self::violations($types, $index) as $violation) {
            $declaring = $by[explode('::', $violation['symbol'])[0]] ?? null;
            $target = $by[$violation['target']] ?? null;
            $signature[] = [
                ...$violation,
                'key' => Allowlist::key($violation),
                'declaring_surface' => $declaring['surface'] ?? 'unknown',
                'target_surface' => $target['surface'] ?? 'unknown',
                'target_is_eloquent' => $index->isEloquent($violation['target']),
                'rule' => $violation['view'] === 'unclassified' ? 'D27: exactly one surface attribute' : 'D27: a supported signature names no Internal type and no Eloquent model of the domain',
            ];
        }

        $found = [];
        $report = static function (array $type, string $symbol, string $kind, string $target, ?string $category = null) use (&$found, $by, $index): void {
            $category ??= match (true) {
                !isset($by[$target]) => str_starts_with($target, 'Vaulter\\') ? 'review' : null,
                $index->isEloquent($target) => 'domain-model',
                $by[$target]['surface'] === 'Internal' => 'internal-type',
                default => null,
            };
            if ($category !== null) {
                $found[] = ['invariant' => self::INVARIANT, 'view' => 'extended', 'file' => $type['file'], 'symbol' => $symbol, 'kind' => $kind, 'target' => $target, 'target_owner' => $by[$target]['package'] ?? (SourceIndex::packageOfFile($type['file']) ?? ''), 'count' => 1, 'category' => $category];
            }
        };
        foreach ($types as $type) {
            if (!in_array($type['surface'], ['Api', 'Spi:Implement', 'Spi:Call'], true)) {
                continue;
            }
            foreach ($type['facade'] ?? [] as $method => $targets) {
                foreach ($targets as $target) {
                    $report($type, $type['fqcn'].'::'.$method.'()', 'facade-method', $target);
                }
            }
            foreach ($type['generics'] ?? [] as $generic) {
                $report($type, $type['fqcn'].($generic['member'] === '' ? '' : '::'.$generic['member']), $generic['kind'], $generic['target']);
            }
            // The ancestors: an Internal one does not hide what the supported child inherits from it; one outside the sources is review.
            $seen = [$type['fqcn'] => true];
            foreach ($type['ancestors'] ?? [] as $ancestor) {
                $queue = [$ancestor];
                while ($queue !== []) {
                    $current = array_shift($queue);
                    if (isset($seen[$current])) {
                        continue;
                    }
                    $seen[$current] = true;
                    if ($current === self::ORM_FACTORY) {
                        $report($type, $type['fqcn'], 'ancestor', $current, 'orm-factory');

                        continue;
                    }
                    if (!isset($by[$current])) {
                        str_starts_with($current, 'Vaulter\\') && $report($type, $type['fqcn'], 'ancestor', $current, 'review');

                        continue;
                    }
                    $parent = $by[$current];
                    if ($parent['surface'] === 'Internal') {
                        foreach ($parent['members'] as $member) {
                            foreach ($member['types'] as $target) {
                                $report($type, $type['fqcn'].'::'.$member['name'], 'inherited-'.$member['kind'], $target);
                            }
                        }
                        foreach ($parent['generics'] ?? [] as $generic) {
                            if ($generic['member'] !== '') {
                                $report($type, $type['fqcn'].'::'.$generic['member'], 'inherited-'.$generic['kind'], $generic['target']);
                            }
                        }
                    }
                    array_push($queue, ...($parent['ancestors'] ?? []));
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

        return ['signature' => $signature, 'extended' => array_values($merged)];
    }

    /**
     * @param  array<Node>                                                                       $nodes
     * @param  array<string, string>                                                             $uses
     * @return list<array{node: Stmt\ClassLike, namespace: string, uses: array<string, string>}>
     */
    private static function classLikes(array $nodes, string $namespace = '', array $uses = []): array
    {
        $found = [];
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                array_push($found, ...self::classLikes($node->stmts, $node->name?->toString() ?? '', []));
            } elseif ($node instanceof Stmt\Use_ && $node->type === Stmt\Use_::TYPE_NORMAL) {
                foreach ($node->uses as $use) {
                    $uses[strtolower($use->getAlias()->toString())] = $use->name->toString();
                }
            } elseif ($node instanceof Stmt\GroupUse) {
                foreach ($node->uses as $use) {
                    if (in_array($use->type, [Stmt\Use_::TYPE_NORMAL, Stmt\Use_::TYPE_UNKNOWN], true) && ($node->type === Stmt\Use_::TYPE_NORMAL || $use->type === Stmt\Use_::TYPE_NORMAL || $node->type === Stmt\Use_::TYPE_UNKNOWN)) {
                        $uses[strtolower($use->getAlias()->toString())] = $node->prefix->toString().'\\'.$use->name->toString();
                    }
                }
            } elseif ($node instanceof Stmt\ClassLike && $node->name !== null) {
                $found[] = ['node' => $node, 'namespace' => $namespace, 'uses' => $uses];
            }
        }

        return $found;
    }

    /**
     * @param  array<string, string> $uses lower-case alias => name, the `use` imports of the file
     * @return SurfaceType
     */
    private static function type(Stmt\ClassLike $node, string $file, string $package, string $namespace = '', array $uses = []): array
    {
        $type = self::typeOf($node, $file, $package);
        $classTemplates = self::templates($node->getDocComment()?->getText() ?? '');
        $resolve = static fn (string $doc, array $templates): array => self::docTypes($doc, $namespace, $uses, [...$classTemplates, ...$templates]);

        $type['facade'] = [];
        foreach ($type['doc'] as $line) {
            if (preg_match('/^@method\s+(?:static\s+)?(.*?)\b(\w+)\s*\((.*)\)/', $line, $m) === 1) {
                $targets = $resolve($m[1].' '.(string) preg_replace('/\$\w+(\s*=\s*[^,]*)?/', '', $m[3]), []);
                $targets !== [] && $type['facade'][$m[2]] = $targets;
            }
        }
        ksort($type['facade'], SORT_STRING);

        $generics = [];
        foreach (self::docTags($node->getDocComment()?->getText() ?? '', ['extends', 'implements', 'use', 'mixin']) as [$tag, $doc]) {
            // `@extends Base<Node>`: the base is an ancestor (walked below), its arguments are what the type hands out.
            foreach ($resolve((string) preg_replace('/^[^<]*/', '', $doc), []) as $target) {
                $generics[] = ['member' => '', 'kind' => $tag.'-generic', 'target' => $target];
            }
        }
        foreach ($node->stmts as $stmt) {
            if (($stmt instanceof Stmt\ClassMethod || $stmt instanceof Stmt\Property || $stmt instanceof Stmt\ClassConst) && !$stmt->isPrivate() && !self::internal($stmt->attrGroups)) {
                $text = $stmt->getDocComment()?->getText() ?? '';
                $templates = self::templates($text);
                $name = match (true) {
                    $stmt instanceof Stmt\ClassMethod => $stmt->name->toString().'()',
                    $stmt instanceof Stmt\Property => '$'.$stmt->props[0]->name->toString(),
                    default => $stmt->consts[0]->name->toString(),
                };
                foreach (self::docTags($text, ['return', 'param', 'var']) as [$tag, $doc]) {
                    foreach ($resolve($doc, $templates) as $target) {
                        $generics[] = ['member' => $name, 'kind' => 'doc-'.$tag, 'target' => $target];
                    }
                }
            }
            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toString() === '__construct') {
                // A promoted property states its generic type in the constructor docblock (`@param list<Node> $nodes`), whatever the constructor is.
                $promoted = [];
                foreach ($stmt->params as $param) {
                    if ($param->flags !== 0 && ($param->flags & Modifiers::PRIVATE) === 0 && !self::internal($param->attrGroups)) {
                        $promoted[self::paramName($param)] = true;
                    }
                }
                foreach (self::docTags($stmt->getDocComment()?->getText() ?? '', ['param'], true) as [, $doc, $var]) {
                    if (isset($promoted[$var])) {
                        foreach ($resolve($doc, []) as $target) {
                            $generics[] = ['member' => '$'.$var, 'kind' => 'doc-property', 'target' => $target];
                        }
                    }
                }
            }
        }
        $type['generics'] = $generics;

        $ancestors = match (true) {
            $node instanceof Stmt\Class_ => $node->extends === null ? [] : [$node->extends->toString()],
            $node instanceof Stmt\Interface_ => array_map(static fn (Name $n): string => $n->toString(), $node->extends),
            default => [],
        };
        foreach ($node->getTraitUses() as $use) {
            foreach ($use->traits as $trait) {
                $ancestors[] = $trait->toString();
            }
        }
        $type['ancestors'] = $ancestors;
        $type['structure'] = self::structure($node, $type['fqcn'], $type['surface'], $type['since']);

        return $type;
    }

    /**
     * The structural view of a type for the BC check: modifiers, ancestors with trait adaptations, attributes, the contract
     * docblock tags, and every public or protected member by key — methods case-insensitively (`method:<lower>`), properties,
     * constants and cases by their exact name. An Internal member keeps only its key, visibility and the flag, so a public
     * member turned Internal is visible; private members are no surface. A syntax the reader does not know is an error.
     *
     * @return array<string, mixed>
     */
    private static function structure(Stmt\ClassLike $node, string $fqcn, string $surface, string $since): array
    {
        $printer = new Standard;
        $parent = $node instanceof Stmt\Class_ ? $node->extends?->toString() : null;
        $context = ['self' => $fqcn, 'parent' => $parent];
        $kind = match (true) {
            $node instanceof Stmt\Interface_ => 'interface',
            $node instanceof Stmt\Trait_ => 'trait',
            $node instanceof Stmt\Enum_ => 'enum',
            $node instanceof Stmt\Class_ => 'class',
            default => throw new InventoryError("{$fqcn}: unknown class-like ".$node::class),
        };
        $names = static fn (array $list): array => self::sorted(array_map(static fn (Name $n): string => $n->toString(), $list));

        $traits = [];
        $adaptations = [];
        $members = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                array_push($traits, ...array_map(static fn (Name $n): string => $n->toString(), $stmt->traits));
                foreach ($stmt->adaptations as $adaptation) {
                    $adaptations[] = match (true) {
                        $adaptation instanceof Stmt\TraitUseAdaptation\Alias => ['trait' => $adaptation->trait?->toString(), 'method' => $adaptation->method->toLowerString(), 'alias' => $adaptation->newName?->toString(), 'visibility' => $adaptation->newModifier === null ? null : self::visibilityOf($adaptation->newModifier), 'insteadof' => []],
                        $adaptation instanceof Stmt\TraitUseAdaptation\Precedence => ['trait' => $adaptation->trait?->toString(), 'method' => $adaptation->method->toLowerString(), 'alias' => null, 'visibility' => null, 'insteadof' => $names($adaptation->insteadof)],
                        default => throw new InventoryError("{$fqcn}: unknown trait adaptation ".$adaptation::class),
                    };
                }
            } elseif ($stmt instanceof Stmt\ClassMethod) {
                $key = 'method:'.$stmt->name->toLowerString();
                if (!$stmt->isPrivate()) {
                    self::put($members, $key, self::method($stmt, $printer, $context), $fqcn);
                }
                if ($stmt->name->toLowerString() === '__construct') {
                    foreach ($stmt->params as $param) {
                        if ($param->flags !== 0 && ($param->flags & Modifiers::PRIVATE) === 0) {
                            self::put($members, 'property:'.self::paramName($param), self::promoted($param, $printer, $context), $fqcn);
                        }
                    }
                }
            } elseif ($stmt instanceof Stmt\Property) {
                foreach ($stmt->props as $property) {
                    if (!$stmt->isPrivate()) {
                        self::put($members, 'property:'.$property->name->toString(), self::property($stmt, $property, $printer, $context), $fqcn);
                    }
                }
            } elseif ($stmt instanceof Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    if (!$stmt->isPrivate()) {
                        self::put($members, 'constant:'.$const->name->toString(), self::constant($stmt, $const, $printer, $context), $fqcn);
                    }
                }
            } elseif ($stmt instanceof Stmt\EnumCase) {
                self::put($members, 'case:'.$stmt->name->toString(), [
                    'kind' => 'case',
                    'name' => $stmt->name->toString(),
                    'value' => $stmt->expr === null ? null : self::expr($printer, $stmt->expr),
                    'attributes' => self::attributes($stmt->attrGroups, $printer),
                    'deprecated' => self::deprecated($stmt->attrGroups, $stmt->getDocComment()?->getText() ?? ''),
                    'doc' => self::contractDoc($stmt->getDocComment()?->getText() ?? ''),
                ], $fqcn);
            } elseif (!$stmt instanceof Stmt\Nop) {
                throw new InventoryError("{$fqcn}: unknown class statement ".$stmt::class);
            }
        }
        ksort($members, SORT_STRING);
        $doc = $node->getDocComment()?->getText() ?? '';

        return [
            'kind' => $kind,
            'surface' => $surface,
            'since' => $since,
            'modifiers' => [
                'abstract' => $node instanceof Stmt\Class_ && $node->isAbstract(),
                'final' => $node instanceof Stmt\Class_ && $node->isFinal(),
                'readonly' => $node instanceof Stmt\Class_ && $node->isReadonly(),
            ],
            'backing' => $node instanceof Stmt\Enum_ ? $node->scalarType?->toLowerString() : null,
            'extends' => match (true) {
                $node instanceof Stmt\Class_ => $parent === null ? [] : [$parent],
                $node instanceof Stmt\Interface_ => $names($node->extends),
                default => [],
            },
            'implements' => $node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_ ? $names($node->implements) : [],
            'traits' => self::sorted($traits),
            'adaptations' => $adaptations,
            'attributes' => self::attributes($node->attrGroups, $printer),
            'deprecated' => self::deprecated($node->attrGroups, $doc),
            'doc_internal' => preg_match('/@internal\b/', $doc) === 1,
            'doc' => self::contractDoc($doc),
            'members' => (object) $members,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $members
     * @param array<string, mixed>                $member
     */
    private static function put(array &$members, string $key, array $member, string $fqcn): void
    {
        if (isset($members[$key])) {
            throw new InventoryError("{$fqcn}: duplicate member key {$key}");
        }
        $members[$key] = $member;
    }

    /**
     * @param  array{self: string, parent: string|null} $context
     * @return array<string, mixed>
     */
    private static function method(Stmt\ClassMethod $stmt, Standard $printer, array $context): array
    {
        if (self::internal($stmt->attrGroups)) {
            return ['kind' => 'method', 'name' => $stmt->name->toString(), 'visibility' => self::visibilityOf($stmt->flags), 'internal' => true];
        }
        $doc = $stmt->getDocComment()?->getText() ?? '';
        $params = [];
        foreach ($stmt->params as $param) {
            $params[] = [
                'name' => self::paramName($param),
                'type' => self::nativeType($param->type, $context),
                'default' => $param->default === null ? null : self::expr($printer, $param->default),
                'variadic' => $param->variadic,
                'by_ref' => $param->byRef,
                'promoted' => $param->flags !== 0,
                'attributes' => self::attributes($param->attrGroups, $printer),
            ];
        }

        return [
            'kind' => 'method',
            'name' => $stmt->name->toString(),
            'visibility' => self::visibilityOf($stmt->flags),
            'internal' => false,
            'static' => $stmt->isStatic(),
            'abstract' => $stmt->isAbstract() || $stmt->stmts === null,
            'final' => $stmt->isFinal(),
            'by_ref' => $stmt->byRef,
            'params' => $params,
            'return' => self::nativeType($stmt->returnType, $context),
            'attributes' => self::attributes($stmt->attrGroups, $printer),
            'deprecated' => self::deprecated($stmt->attrGroups, $doc),
            'doc_internal' => preg_match('/@internal\b/', $doc) === 1,
            'doc' => self::contractDoc($doc),
        ];
    }

    /**
     * @param  array{self: string, parent: string|null} $context
     * @return array<string, mixed>
     */
    private static function promoted(Node\Param $param, Standard $printer, array $context): array
    {
        if (self::internal($param->attrGroups)) {
            return ['kind' => 'property', 'name' => self::paramName($param), 'visibility' => self::visibilityOf($param->flags), 'internal' => true];
        }

        return [
            'kind' => 'property',
            'name' => self::paramName($param),
            'visibility' => self::visibilityOf($param->flags),
            'set_visibility' => self::setVisibility($param->flags),
            'internal' => false,
            'promoted' => true,
            'static' => false,
            'readonly' => ($param->flags & Modifiers::READONLY) !== 0,
            'abstract' => false,
            'final' => ($param->flags & Modifiers::FINAL) !== 0,
            'type' => self::nativeType($param->type, $context),
            'default' => null,
            'hooks' => self::hooks($param->hooks, $printer, $context),
            'attributes' => self::attributes($param->attrGroups, $printer),
            'deprecated' => self::deprecated($param->attrGroups, ''),
            'doc_internal' => false,
            'doc' => [],
        ];
    }

    /**
     * @param  array{self: string, parent: string|null} $context
     * @return array<string, mixed>
     */
    private static function property(Stmt\Property $stmt, Node\PropertyItem $property, Standard $printer, array $context): array
    {
        if (self::internal($stmt->attrGroups)) {
            return ['kind' => 'property', 'name' => $property->name->toString(), 'visibility' => self::visibilityOf($stmt->flags), 'internal' => true];
        }
        $doc = $stmt->getDocComment()?->getText() ?? '';

        return [
            'kind' => 'property',
            'name' => $property->name->toString(),
            'visibility' => self::visibilityOf($stmt->flags),
            'set_visibility' => self::setVisibility($stmt->flags),
            'internal' => false,
            'promoted' => false,
            'static' => $stmt->isStatic(),
            'readonly' => $stmt->isReadonly(),
            'abstract' => $stmt->isAbstract(),
            'final' => $stmt->isFinal(),
            'type' => self::nativeType($stmt->type, $context),
            'default' => $property->default === null ? null : self::expr($printer, $property->default),
            'hooks' => self::hooks($stmt->hooks, $printer, $context),
            'attributes' => self::attributes($stmt->attrGroups, $printer),
            'deprecated' => self::deprecated($stmt->attrGroups, $doc),
            'doc_internal' => preg_match('/@internal\b/', $doc) === 1,
            'doc' => self::contractDoc($doc),
        ];
    }

    /**
     * @param  array{self: string, parent: string|null} $context
     * @return array<string, mixed>
     */
    private static function constant(Stmt\ClassConst $stmt, Node\Const_ $const, Standard $printer, array $context): array
    {
        if (self::internal($stmt->attrGroups)) {
            return ['kind' => 'constant', 'name' => $const->name->toString(), 'visibility' => self::visibilityOf($stmt->flags), 'internal' => true];
        }
        $doc = $stmt->getDocComment()?->getText() ?? '';

        return [
            'kind' => 'constant',
            'name' => $const->name->toString(),
            'visibility' => self::visibilityOf($stmt->flags),
            'internal' => false,
            'final' => $stmt->isFinal(),
            'type' => self::nativeType($stmt->type, $context),
            'value' => self::expr($printer, $const->value),
            'attributes' => self::attributes($stmt->attrGroups, $printer),
            'deprecated' => self::deprecated($stmt->attrGroups, $doc),
            'doc_internal' => preg_match('/@internal\b/', $doc) === 1,
            'doc' => self::contractDoc($doc),
        ];
    }

    /**
     * @param  list<Node\PropertyHook>                  $hooks
     * @param  array{self: string, parent: string|null} $context
     * @return list<array<string, mixed>>
     */
    private static function hooks(array $hooks, Standard $printer, array $context): array
    {
        $found = [];
        foreach ($hooks as $hook) {
            $found[] = [
                'name' => $hook->name->toLowerString(),
                'final' => $hook->isFinal(),
                'abstract' => $hook->body === null,
                'by_ref' => $hook->byRef,
                'params' => array_map(static fn (Node\Param $p): array => ['name' => self::paramName($p), 'type' => self::nativeType($p->type, $context)], $hook->params),
                'attributes' => self::attributes($hook->attrGroups, $printer),
            ];
        }
        usort($found, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $found;
    }

    /**
     * A native type in a normal form: `?T` is `T|null`, union members sorted, an intersection inside a union in parentheses,
     * `self`/`parent` resolved in the declaring type, builtin names lower-case. An unknown type node is an error.
     *
     * @param array{self: string, parent: string|null} $context
     */
    private static function nativeType(?Node $type, array $context, bool $nested = false): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\Identifier) {
            return $type->toLowerString();
        }
        if ($type instanceof Name) {
            return match (strtolower($type->toString())) {
                'self' => $context['self'],
                'parent' => $context['parent'] ?? throw new InventoryError("{$context['self']}: `parent` in a type without a parent"),
                'static' => 'static',
                default => $type->toString(),
            };
        }
        if ($type instanceof Node\NullableType) {
            return self::union([self::nativeType($type->type, $context, true), 'null']);
        }
        if ($type instanceof Node\UnionType) {
            return self::union(array_map(static fn (Node $t): ?string => self::nativeType($t, $context, true), $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            $parts = self::sorted(array_map(static fn (Node $t): string => (string) self::nativeType($t, $context, true), $type->types));

            return $nested ? '('.implode('&', $parts).')' : implode('&', $parts);
        }

        throw new InventoryError("{$context['self']}: unknown type node ".$type::class);
    }

    /** @param list<string|null> $parts */
    private static function union(array $parts): string
    {
        $flat = [];
        foreach ($parts as $part) {
            // A nested `?T` inside a union cannot be written, but a normal form must not depend on that.
            array_push($flat, ...(str_starts_with((string) $part, '(') ? [(string) $part] : explode('|', (string) $part)));
        }

        return implode('|', self::sorted(array_values(array_unique($flat))));
    }

    /**
     * @param  list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return array_values($values);
    }

    /**
     * Every attribute with its arguments, printed with resolved names and sorted: the order of attributes is no contract.
     *
     * @param  list<Node\AttributeGroup> $groups
     * @return list<string>
     */
    private static function attributes(array $groups, Standard $printer): array
    {
        $found = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $arguments = array_map(static fn (Node\Arg $a): string => ($a->name === null ? '' : $a->name->toString().': ').self::expr($printer, $a->value), $attribute->args);
                $found[] = $attribute->name->toString().($arguments === [] ? '' : '('.implode(', ', $arguments).')');
            }
        }

        return self::sorted($found);
    }

    /** @param list<Node\AttributeGroup> $groups */
    private static function deprecated(array $groups, string $doc): bool
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if (strtolower($attribute->name->toString()) === 'deprecated') {
                    return true;
                }
            }
        }

        return preg_match('/@deprecated\b/', $doc) === 1;
    }

    /**
     * The contract tags of a docblock, one normalised line each (continuation lines joined), sorted.
     *
     * @return list<string>
     */
    private static function contractDoc(string $doc): array
    {
        if ($doc === '') {
            return [];
        }
        $text = (string) preg_replace(['#^\s*/\*\*#', '#\*/\s*$#', '#^\s*\*[ \t]?#m'], '', $doc);
        $tags = [];
        $current = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '@')) {
                $current !== null && $tags[] = $current;
                $current = $line;
            } elseif ($current !== null && $line !== '') {
                $current .= ' '.$line;
            } else {
                $current !== null && $tags[] = $current;
                $current = null;
            }
        }
        $current !== null && $tags[] = $current;
        // A typed tag keeps its type and variable, not its prose; the other contract tags keep their whole line.
        $typed = ['param', 'param-out', 'var', 'return', 'throws', 'property', 'property-read', 'property-write'];
        $kept = [];
        foreach (self::docTags($doc, $typed, true) as [$tag, $type, $variable]) {
            $kept[] = trim((string) preg_replace('/\s+/', ' ', "@{$tag} {$type}".($variable === '' ? '' : " \${$variable}")));
        }
        foreach ($tags as $tag) {
            if (preg_match(self::CONTRACT_TAGS, $tag) === 1 && preg_match('/^@(?:phpstan-|psalm-)?(?:'.implode('|', $typed).')(?![\w-])/', $tag) !== 1) {
                $kept[] = trim((string) preg_replace('/\s+/', ' ', $tag));
            }
        }

        return self::sorted($kept);
    }

    private static function visibilityOf(int $flags): string
    {
        return match (true) {
            ($flags & Modifiers::PRIVATE) !== 0 => 'private',
            ($flags & Modifiers::PROTECTED) !== 0 => 'protected',
            default => 'public',
        };
    }

    private static function setVisibility(int $flags): ?string
    {
        return match (true) {
            ($flags & Modifiers::PRIVATE_SET) !== 0 => 'private',
            ($flags & Modifiers::PROTECTED_SET) !== 0 => 'protected',
            ($flags & Modifiers::PUBLIC_SET) !== 0 => 'public',
            default => null,
        };
    }

    /** @return list<string> the template names a docblock declares */
    private static function templates(string $doc): array
    {
        preg_match_all('/@(?:phpstan-|psalm-)?template(?:-covariant|-contravariant)?\s+(\w+)/', $doc, $m);

        return $m[1];
    }

    /**
     * The tags of a docblock with their type: `@return Collection<int, Node>` => ['return', 'Collection<int, Node>'].
     *
     * @param  list<string>                                 $tags
     * @return list<array{0: string, 1: string, 2: string}> tag, type, variable name (of a `@param`)
     */
    private static function docTags(string $doc, array $tags, bool $withVariable = false): array
    {
        $found = [];
        $text = (string) preg_replace(['#^\s*/\*\*#', '#\*/\s*$#', '#^\s*\*\s?#m'], '', $doc);
        $alternatives = implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $tags));
        if (preg_match_all('/@(?:phpstan-|psalm-)?('.$alternatives.')\s+/', $text, $m, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }
        foreach ($m[0] as $i => [$whole, $offset]) {
            $tag = $m[1][$i][0];
            $start = $offset + strlen($whole);
            $depth = 0;
            $end = $start;
            $length = strlen($text);
            while ($end < $length) {
                $char = $text[$end];
                if (str_contains('<{(', $char)) {
                    $depth++;
                } elseif (str_contains('>})', $char)) {
                    $depth--;
                } elseif ($depth <= 0 && ctype_space($char)) {
                    // `int | Node`, `array<int, Node> $x`: a space ends the type only outside brackets and not around a union.
                    $rest = ltrim(substr($text, $end));
                    if ($rest === '' || !str_contains('|&', $rest[0])) {
                        if (!str_ends_with(rtrim(substr($text, $start, $end - $start)), '|') && !str_ends_with(rtrim(substr($text, $start, $end - $start)), '&')) {
                            break;
                        }
                    }
                }
                $end++;
            }
            $type = trim(substr($text, $start, $end - $start));
            $variable = $withVariable && preg_match('/^\s*(?:\.\.\.)?&?\$(\w+)/', substr($text, $end), $v) === 1 ? $v[1] : '';
            $found[] = [$tag, $type, $variable];
        }

        return $found;
    }

    /**
     * The class names a PHPDoc type names, resolved against the namespace and the imports of the file.
     *
     * @param  array<string, string> $uses
     * @param  list<string>          $templates
     * @return list<string>
     */
    private static function docTypes(string $doc, string $namespace, array $uses, array $templates): array
    {
        $doc = (string) preg_replace([
            "/'[^']*'|\"[^\"]*\"/", // literal strings
            '/::\w+\*?/', // constants of a class: the class still counts
            '/(?<![\w\\\\-])[\w-]+\??\s*:(?!:)/', // the keys of an array shape or the return arrow of a callable
            '/\$\w+/', // variables of a callable signature
        ], ['', '', ' ', ' '], $doc);
        preg_match_all('/\\\\?[A-Za-z_][\w-]*(?:\\\\[A-Za-z_]\w*)*/', $doc, $m);
        $names = [];
        foreach ($m[0] as $word) {
            $lower = strtolower(ltrim($word, '\\'));
            if (in_array($lower, self::DOC_WORDS, true) || in_array(ltrim($word, '\\'), $templates, true) || str_contains($word, '-') || is_numeric($word)) {
                continue;
            }
            if (str_starts_with($word, '\\')) {
                $names[] = substr($word, 1);

                continue;
            }
            $first = strtolower(explode('\\', $word)[0]);
            $names[] = match (true) {
                isset($uses[$first]) => $uses[$first].substr($word, strlen($first)),
                $namespace === '' => $word,
                default => $namespace.'\\'.$word,
            };
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return SurfaceType */
    private static function typeOf(Stmt\ClassLike $node, string $file, string $package): array
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
