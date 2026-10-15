<?php

declare(strict_types=1);

/*
 * scripts/php84 php scripts/inventory/apply-surface.php (--write | --check)
 *
 * Puts the surface attribute of D27 on every type of packages/*\/src that the target-map classifies — #[Api],
 * #[Spi(SpiKind::Implement|Call)] or #[Internal] — and #[Internal] on the technical constructor of an Api class (a
 * constructor that needs a type the host must not name, or the container). A type that already carries a surface
 * attribute is left alone, so the script is idempotent; `--check` prints what --write would do and exits 1 when
 * there is something to do. `@internal` in the docblock of an Internal type is dropped: the attribute replaces it.
 *
 * Run Pint afterwards (the new `use` lines are appended after the last import, Pint orders them).
 */

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Vaulter\Inventory\Repo;

require_once __DIR__.'/bootstrap.php';

const SURFACE_ATTRIBUTES = ['Vaulter\Attributes\Api', 'Vaulter\Attributes\Spi', 'Vaulter\Attributes\Internal'];

/** Framework types a host cannot hand to a constructor: the container and the infrastructure behind it. */
const INFRASTRUCTURE = [
    'Illuminate\Container\Container',
    'Illuminate\Contracts\Container\Container',
    'Illuminate\Contracts\Foundation\Application',
    'Illuminate\Foundation\Application',
    'Illuminate\Database\ConnectionInterface',
    'Illuminate\Database\Connection',
    'Illuminate\Database\ConnectionResolverInterface',
    'Illuminate\Contracts\Config\Repository',
    'Illuminate\Contracts\Events\Dispatcher',
    'Illuminate\Contracts\Cache\Repository',
];

$mode = $argv[1] ?? '';
if (!in_array($mode, ['--write', '--check'], true)) {
    fwrite(STDERR, "usage: apply-surface.php (--write | --check)\n");
    exit(2);
}

$repo = Repo::discover();
$map = json_decode($repo->read('tests/Repo/Arch/Fixtures/target-map.json'), true, flags: JSON_THROW_ON_ERROR);

/** @var array<string, string> $surface fqcn => Api | Internal | Spi:Implement | Spi:Call */
$surface = [];
/** @var array<string, list<array{fqcn: string, kind: string}>> $byFile */
$byFile = [];
foreach ($map['types'] as $type) {
    $surface[$type['fqcn']] = $type['surface'];
    $byFile[$type['file']][] = ['fqcn' => $type['fqcn'], 'kind' => $type['kind']];
}
ksort($byFile, SORT_STRING);

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$changed = 0;
$counts = ['type' => 0, 'constructor' => 0, 'docblock' => 0, 'fqn' => 0, 'member' => 0];
/** @var array<int, true> $handled members that already got #[Internal] in this run */
$handled = [];

foreach ($byFile as $file => $types) {
    $code = $repo->read($file);
    $ast = $parser->parse($code) ?? [];
    $ast = (new NodeTraverser(new NameResolver))->traverse($ast);
    $finder = new NodeFinder;
    /** @var list<array{int, string}> $edits */
    $edits = [];
    $imports = [];
    $namespace = $finder->findFirstInstanceOf($ast, Stmt\Namespace_::class);
    $namespaceName = $namespace?->name?->toString() ?? '';
    $needsFqn = static function (string $short) use ($code, $namespaceName, $repo, $file): bool {
        if (preg_match('/^use\s+(?!Vaulter\\\\Attributes\\\\)[^;]*\\\\'.$short.'(\s+as\s+\w+)?;/m', $code) === 1) {
            return true;
        }

        return $namespaceName !== 'Vaulter\Attributes' && file_exists(dirname($repo->path($file)).'/'.$short.'.php');
    };

    foreach ($types as ['fqcn' => $fqcn]) {
        $node = $finder->findFirst($ast, static fn (Node $n): bool => $n instanceof Stmt\ClassLike && $n->namespacedName?->toString() === $fqcn);
        if (!$node instanceof Stmt\ClassLike) {
            fwrite(STDERR, "{$file}: {$fqcn} not found in the file\n");
            exit(2);
        }
        $kind = $surface[$fqcn];
        $existing = [];
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $existing[] = $attribute->name->toString();
            }
        }

        if (array_intersect($existing, SURFACE_ATTRIBUTES) === []) {
            [$short, $text] = match ($kind) {
                'Api' => ['Api', '#[%sApi]'],
                'Internal' => ['Internal', '#[%sInternal]'],
                'Spi:Implement' => ['Spi', '#[%sSpi(%sSpiKind::Implement)]'],
                'Spi:Call' => ['Spi', '#[%sSpi(%sSpiKind::Call)]'],
                default => throw new RuntimeException("{$fqcn}: surface {$kind}"),
            };
            $fqn = $needsFqn($short);
            $prefix = $fqn ? '\Vaulter\Attributes\\' : '';
            $line = sprintf($text, $prefix, $prefix);
            if ($namespaceName !== 'Vaulter\Attributes' && !$fqn) {
                $imports['Vaulter\Attributes\\'.$short] = true;
                if (str_starts_with($kind, 'Spi')) {
                    $imports['Vaulter\Attributes\SpiKind'] = true;
                }
            }
            $counts['fqn'] += $fqn ? 1 : 0;
            $counts['type']++;
            $edits[] = [lineStart($code, $node->getStartFilePos()), $line."\n"];

            $doc = $node->getDocComment();
            if ($kind === 'Internal' && $doc !== null && preg_match('/^\s*\*\s*@internal\b/m', $doc->getText()) === 1) {
                $stripped = (string) preg_replace(['/\n[ \t]*\*[ \t]*\n([ \t]*\*[ \t]*@internal[ \t]*\n)/', '/^([ \t]*\*[ \t]*)@internal[ \t]*\n/m', '/^([ \t]*\*[ \t]*)@internal[ \t]+/m'], ["\n", '', '$1'], $doc->getText());
                if ($stripped !== $doc->getText()) {
                    $counts['docblock']++;
                    $edits[] = [-$doc->getStartFilePos() - 1, $doc->getText()."\0".$stripped];
                }
            }
        }

        // A member that documents itself `@internal` in a supported type is #[Internal] now; the tag goes.
        if ($kind !== 'Internal') {
            foreach ($node->stmts as $member) {
                if (!($member instanceof Stmt\ClassMethod || $member instanceof Stmt\Property || $member instanceof Stmt\ClassConst)) {
                    continue;
                }
                $doc = $member->getDocComment();
                if ($doc === null || preg_match('/@internal\b/', $doc->getText()) !== 1 || hasInternal($member)) {
                    continue;
                }
                $handled[spl_object_id($member)] = true;
                $fqn = $needsFqn('Internal');
                if (!$fqn && $namespaceName !== 'Vaulter\Attributes') {
                    $imports['Vaulter\Attributes\Internal'] = true;
                }
                $counts['member']++;
                $start = lineStart($code, $member->getStartFilePos());
                $indent = (string) preg_replace('/\S.*$/s', '', substr($code, $start, 40));
                $edits[] = [$start, $indent.'#['.($fqn ? '\\Vaulter\\Attributes\\' : '').'Internal]'."\n"];
                $cleaned = withoutInternalTag($doc->getText());
                if ($cleaned === null) {
                    $docStart = lineStart($code, $doc->getStartFilePos());
                    $docEnd = strpos($code, "\n", $doc->getEndFilePos());
                    $docEnd = $docEnd === false ? strlen($code) : $docEnd + 1;
                    $edits[] = [-$docStart - 1, substr($code, $docStart, $docEnd - $docStart)."\0"];
                } else {
                    $edits[] = [-$doc->getStartFilePos() - 1, $doc->getText()."\0".$cleaned];
                }
            }
        }

        if ($kind === 'Api' && $node instanceof Stmt\Class_ && technicalConstructor($node, $surface)) {
            $constructor = $node->getMethod('__construct');
            if ($constructor !== null && !hasInternal($constructor) && !isset($handled[spl_object_id($constructor)])) {
                $fqn = $needsFqn('Internal');
                if (!$fqn && $namespaceName !== 'Vaulter\Attributes') {
                    $imports['Vaulter\Attributes\Internal'] = true;
                }
                $counts['constructor']++;
                $start = lineStart($code, $constructor->getStartFilePos());
                $indent = (string) preg_replace('/\S.*$/s', '', substr($code, $start, 40));
                $edits[] = [$start, $indent.'#['.($fqn ? '\Vaulter\Attributes\\' : '').'Internal]'."\n"];
            }
        }
    }

    // Imports: after the last top-level `use` of the namespace, else after the namespace statement.
    $present = [];
    foreach ($finder->findInstanceOf($ast, Stmt\Use_::class) as $use) {
        foreach ($use->uses as $item) {
            $present[$item->name->toString()] = true;
        }
    }
    $new = array_values(array_filter(array_keys($imports), static fn (string $name): bool => !isset($present[$name])));
    sort($new, SORT_STRING);
    if ($new !== []) {
        $lines = implode('', array_map(static fn (string $name): string => "\nuse {$name};", $new));
        $uses = array_values(array_filter($namespace === null ? $ast : $namespace->stmts, static fn (Node $n): bool => $n instanceof Stmt\Use_));
        if ($uses !== []) {
            $edits[] = [end($uses)->getEndFilePos() + 1, $lines];
        } else {
            $edits[] = [namespaceStatementEnd($code, $namespace), "\n".$lines];
        }
    }

    if ($edits === []) {
        continue;
    }
    $changed++;
    if ($mode === '--check') {
        continue;
    }

    // Apply from the end of the file: an edit never moves the position of an earlier one.
    usort($edits, static fn (array $a, array $b): int => abs($b[0]) <=> abs($a[0]));
    foreach ($edits as [$position, $text]) {
        if ($position < 0) { // a docblock replacement: -(start + 1), "old\0new"
            [$old, $new] = explode("\0", $text, 2);
            $start = -$position - 1;
            $code = substr($code, 0, $start).$new.substr($code, $start + strlen($old));

            continue;
        }
        $code = substr($code, 0, $position).$text.substr($code, $position);
    }
    $repo->write($file, $code);
}

fwrite(STDOUT, sprintf("%d files, %d type attributes (%d fully qualified), %d constructors, %d members, %d docblocks\n", $changed, $counts['type'], $counts['fqn'], $counts['constructor'], $counts['member'], $counts['docblock']));
exit($mode === '--check' && $changed > 0 ? 1 : 0);

function lineStart(string $code, int $position): int
{
    $newline = strrpos(substr($code, 0, $position), "\n");

    return $newline === false ? 0 : $newline + 1;
}

function namespaceStatementEnd(string $code, ?Stmt\Namespace_ $namespace): int
{
    if ($namespace === null) {
        $php = strpos($code, '<?php');
        $declare = strpos($code, ';', $php === false ? 0 : $php);

        return $declare === false ? 0 : $declare + 1;
    }
    $name = $namespace->name;
    $semicolon = strpos($code, ';', $name === null ? $namespace->getStartFilePos() : $name->getEndFilePos());

    return $semicolon === false ? $namespace->getStartFilePos() : $semicolon + 1;
}

function hasInternal(Stmt\ClassMethod|Stmt\Property|Stmt\ClassConst $member): bool
{
    foreach ($member->attrGroups as $group) {
        foreach ($group->attrs as $attribute) {
            if ($attribute->name->toString() === 'Vaulter\Attributes\Internal') {
                return true;
            }
        }
    }

    return false;
}

/**
 * A constructor the host cannot call: one of its parameters is a type of Vaulter that the host must not name, or a
 * piece of the container's infrastructure.
 *
 * @param array<string, string> $surface
 */
function technicalConstructor(Stmt\Class_ $class, array $surface): bool
{
    $constructor = $class->getMethod('__construct');
    if ($constructor === null) {
        return false;
    }
    foreach ($constructor->params as $param) {
        foreach (typeNames($param->type) as $name) {
            if (($surface[$name] ?? null) === 'Internal' || in_array($name, INFRASTRUCTURE, true)) {
                return true;
            }
        }
    }

    return false;
}

/** @return list<string> */
function typeNames(?Node $type): array
{
    return match (true) {
        $type === null => [],
        $type instanceof Node\Name => [$type->toString()],
        $type instanceof Node\NullableType => typeNames($type->type),
        $type instanceof Node\UnionType, $type instanceof Node\IntersectionType => array_merge(...array_map(typeNames(...), $type->types)),
        default => [],
    };
}

/** The docblock without its `@internal` tag; null when nothing else is left (the docblock goes). */
function withoutInternalTag(string $doc): ?string
{
    if (!str_contains($doc, "\n")) { // /** @internal */ or /** @internal text */
        $text = trim((string) preg_replace(['#^/\*\*#', '#\*/$#', '/@internal\b/'], '', $doc));

        return $text === '' ? null : '/** '.$text.' */';
    }
    $lines = explode("\n", $doc);
    $kept = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^(\s*\*\s*)@internal\s*$/', $line) === 1) {
            // the tag alone: drop it with the blank line that separated it from what follows
            if (preg_match('/^\s*\*\s*$/', $lines[$i + 1] ?? '') === 1) {
                $lines[$i + 1] = "\0";
            } elseif (preg_match('/^\s*\*\s*$/', (string) end($kept)) === 1) {
                array_pop($kept);
            }

            continue;
        }
        if ($line === "\0") {
            continue;
        }
        $kept[] = preg_replace('/^(\s*\*\s*)@internal\s+/', '$1', $line);
    }
    $body = array_filter($kept, static fn (string $line): bool => preg_match('#^\s*(/\*\*|\*/|\*)\s*$#', $line) !== 1);

    return $body === [] ? null : implode("\n", $kept);
}
