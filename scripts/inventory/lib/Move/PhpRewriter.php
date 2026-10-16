<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Move;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Rewrites one PHP file for a set of moved types: the `use` imports, the namespace of a moved file, every class name
 * (fully qualified, imported or resolved through the namespace) and, through the tokens, the names inside string
 * literals and comments. Edits are text splices by node position, applied from the end of the file, so the rest of
 * the source (formatting, comments) stays byte-identical. A name that used to resolve through the namespace and no
 * longer does gets an import (an alias on a clash of short names).
 *
 * @phpstan-type Result array{code: string, edits: int, strings: int, warnings: list<string>}
 */
final class PhpRewriter
{
    private readonly Parser $parser;

    /**
     * @param array<string, string>               $map       old FQCN => new FQCN of every moved type
     * @param array<string, array{string,string}> $nsRewrite file => [old namespace prefix, new prefix], for moved files without types
     * @param array<string, true>                 $known     every type of the code base (old FQCN), to tell a class in a docblock from a template name
     * @param ?Closure(string): string            $package   the package name (Studly) a FQCN belongs to, for aliases
     */
    public function __construct(
        private readonly array $map,
        private readonly FqcnText $text,
        private readonly array $nsRewrite = [],
        private readonly array $known = [],
        private readonly ?Closure $package = null,
    ) {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /** @return Result */
    public function rewrite(string $file, string $code): array
    {
        try {
            $ast = $this->parser->parse($code) ?? [];
            $tokens = $this->parser->getTokens();
        } catch (Throwable $e) {
            throw new MoveFailure("{$file}: {$e->getMessage()}", 0, $e);
        }
        $collector = new Collector;
        $traverser = new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]), $collector);
        $traverser->traverse($ast);

        /** @var list<array{int, int, string}> $ops */
        $ops = [];
        $warnings = [];
        $oldNs = $collector->namespaces === [] ? '' : $collector->namespaces[0]->name?->toString() ?? '';
        $newNs = $this->newNamespace($file, $oldNs, $collector);

        if ($newNs !== $oldNs && $collector->namespaces !== [] && $collector->namespaces[0]->name !== null) {
            $name = $collector->namespaces[0]->name;
            $ops[] = [$name->getStartFilePos(), $name->getEndFilePos() + 1, $newNs];
        }

        // imports: [lower alias => final target] of the file once its own imports are rewritten
        $imports = [];
        foreach ($collector->uses as $use) {
            $this->rewriteUse($use, $ops, $imports);
        }

        $declared = [];
        foreach ($collector->declared as $short => $fqcn) {
            $declared[strtolower($short)] = $this->map[$fqcn] ?? $fqcn;
        }

        /** @var array<string, list<Name>> $needs final FQCN => the unqualified names that now need an import */
        $needs = [];
        foreach ($collector->names as $name) {
            $this->rewriteName($name, $imports, $newNs, $ops, $needs);
        }

        $original = $imports;
        $lines = $this->imports($needs, $imports, $declared, $ops, $warnings, $file);
        if ($lines !== []) {
            $ops[] = $this->insertion($collector, $tokens, $code, $lines);
        }
        $edits = count($ops);

        $strings = 0;
        foreach ($tokens as $token) {
            if (in_array($token->id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $count = 0;
                $replaced = $this->text->replace($token->text, $count);
                if ($token->id === T_DOC_COMMENT) {
                    $replaced = $this->docblock($replaced, $oldNs, $newNs, $original, $imports, $count);
                }
                if ($count > 0) {
                    $ops[] = [$token->pos, $token->pos + strlen($token->text), $replaced];
                    $strings += $count;
                }
            }
        }

        if ($ops !== [] && count($collector->namespaces) > 1) {
            throw new MoveFailure("{$file}: several namespaces in one file are not supported");
        }

        return ['code' => self::splice($file, $code, $ops), 'edits' => $edits, 'strings' => $strings, 'warnings' => $warnings];
    }

    /**
     * The class names written in the type part of a docblock tag resolve like code does: a name that used to resolve
     * through the namespace and now would not is written fully qualified. A name no class of the code base has (a
     * template, a pseudo-type) stays, and so does one the file imports.
     *
     * @param array<string, string> $original lower alias => target, the imports before the move
     * @param array<string, string> $imports  the imports after it
     */
    private function docblock(string $doc, string $oldNs, string $newNs, array $original, array $imports, int &$count): string
    {
        $patterns = [
            '/@(?:phpstan-|psalm-)?(?:param|param-out|return|var|throws|property|property-read|property-write|extends|implements|use|mixin|template-extends|template-implements|assert|assert-if-true|assert-if-false|this-out|self-out)\s+/',
            '/@(?:phpstan-|psalm-)?type\s+\w+\s*=?\s*/',
            '/@(?:phpstan-|psalm-)?template(?:-covariant|-contravariant)?\s+\w+\s+of\s+/',
            '/@(?:phpstan-|psalm-)?import-type\s+\w+\s+from\s+/',
        ];
        /** @var list<array{int, int}> $spans */
        $spans = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $doc, $found, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($found[0] as [$match, $at]) {
                $start = $at + strlen($match);
                $spans[] = [$start, self::typeEnd($doc, $start)];
            }
        }
        rsort($spans);
        foreach ($spans as [$start, $end]) {
            $type = substr($doc, $start, $end - $start);
            $rewritten = preg_replace_callback(
                '/(?<![\w\\\\$:>\'"-])([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(?![\w\\\\(]|\??\s*:(?!:))/',
                function (array $m) use ($oldNs, $newNs, $original, $imports, &$count): string {
                    $written = $m[1];
                    $first = strtolower(explode('\\', $written)[0]);
                    if (isset($original[$first])) {
                        $resolved = $original[$first].substr($written, strlen($first));
                        $target = $this->map[$resolved] ?? $resolved;
                        if (!str_contains($written, '\\') || $target === $resolved) {
                            return $m[0];
                        }
                        $count++;

                        return '\\'.$target;
                    }
                    $resolved = ($oldNs === '' ? '' : $oldNs.'\\').$written;
                    if (!isset($this->map[$resolved]) && !($oldNs !== $newNs && isset($this->known[$resolved]))) {
                        return $m[0];
                    }
                    $target = $this->map[$resolved] ?? $resolved;
                    if ((($newNs === '' ? '' : $newNs.'\\').$written) === $target || ($imports[$first] ?? null) === $target) {
                        return $m[0];
                    }
                    $count++;

                    return '\\'.$target;
                },
                $type,
            );
            $doc = substr($doc, 0, $start).($rewritten ?? $type).substr($doc, $end);
        }

        return $doc;
    }

    /** The end of the type expression that starts at an offset: the first space outside brackets, braces and parentheses. */
    private static function typeEnd(string $doc, int $from): int
    {
        $depth = 0;
        for ($i = $from, $length = strlen($doc); $i < $length; $i++) {
            $c = $doc[$i];
            if (str_contains('<[{(', $c)) {
                $depth++;
            } elseif (str_contains('>]})', $c)) {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || ($c === '*' && ($doc[$i + 1] ?? '') === '/'))) {
                return $i;
            }
        }

        return $length;
    }

    /** The namespace the file has once moved: that of the new FQCN of its types, else a rewritten prefix, else unchanged. */
    private function newNamespace(string $file, string $oldNs, Collector $collector): string
    {
        $targets = [];
        $stays = [];
        foreach ($collector->declared as $fqcn) {
            if (isset($this->map[$fqcn])) {
                $targets[self::namespaceOf($this->map[$fqcn])] = true;
            } else {
                $stays[] = $fqcn;
            }
        }
        if ($targets !== [] && $stays !== []) {
            throw new MoveUsage("{$file} declares ".implode(', ', $stays).' that is not moved with the rest of the file');
        }
        if (count($targets) > 1) {
            throw new MoveUsage("{$file} declares types that move to different namespaces: ".implode(', ', array_keys($targets)));
        }
        if ($targets !== []) {
            return (string) array_key_first($targets);
        }
        if (isset($this->nsRewrite[$file])) {
            [$from, $to] = $this->nsRewrite[$file];
            if ($oldNs === rtrim($from, '\\') || str_starts_with($oldNs, $from)) {
                return $to.substr($oldNs, strlen($from));
            }
        }

        return $oldNs;
    }

    /**
     * @param list<array{int, int, string}> $ops
     * @param array<string, string>         $imports
     */
    private function rewriteUse(Stmt\Use_|Stmt\GroupUse $use, array &$ops, array &$imports): void
    {
        $hit = false;
        $lines = [];
        foreach ($use->uses as $item) {
            $type = $use->type !== Stmt\Use_::TYPE_UNKNOWN ? $use->type : $item->type;
            $target = ($use instanceof Stmt\GroupUse ? $use->prefix->toString().'\\' : '').$item->name->toString();
            $normal = $type === Stmt\Use_::TYPE_NORMAL;
            $final = $normal ? ($this->map[$target] ?? $target) : $target;
            $alias = $item->alias?->toString();
            if ($normal) {
                $imports[strtolower($alias ?? self::lastSegment($final))] = $final;
            }
            if ($final !== $target) {
                $hit = true;
                if ($use instanceof Stmt\Use_) {
                    $ops[] = [$item->name->getStartFilePos(), $item->name->getEndFilePos() + 1, $final];
                }
            }
            $prefix = match ($type) {
                Stmt\Use_::TYPE_FUNCTION => 'use function ',
                Stmt\Use_::TYPE_CONSTANT => 'use const ',
                default => 'use ',
            };
            $lines[] = $prefix.$final.($alias !== null ? ' as '.$alias : '').';';
        }
        if ($hit && $use instanceof Stmt\GroupUse) {
            $ops[] = [$use->getStartFilePos(), $use->getEndFilePos() + 1, implode("\n", $lines)];
        }
    }

    /**
     * @param array<string, string>         $imports
     * @param list<array{int, int, string}> $ops
     * @param array<string, list<Name>>     $needs
     */
    private function rewriteName(Name $name, array $imports, string $newNs, array &$ops, array &$needs): void
    {
        $resolved = $name->getAttribute('resolvedName');
        if (!$resolved instanceof Name || $name->isSpecialClassName()) {
            return;
        }
        $old = $resolved->toString();
        $start = $name->getStartFilePos();
        $end = $name->getEndFilePos() + 1;

        if ($name->isFullyQualified()) {
            if (isset($this->map[$old])) {
                $ops[] = [$start, $end, '\\'.$this->map[$old]];
            }

            return;
        }
        if (!$name->isRelative() && isset($imports[strtolower($name->getFirst())])) {
            if (!$name->isUnqualified() && isset($this->map[$old])) {
                $ops[] = [$start, $end, '\\'.$this->map[$old]];
            }

            return;
        }

        // resolved through the namespace of the file
        $target = $this->map[$old] ?? $old;
        $local = ($newNs === '' ? '' : $newNs.'\\').implode('\\', $name->getParts());
        if ($local === $target) {
            return;
        }
        if ($name->isUnqualified()) {
            $needs[$target][] = $name;

            return;
        }
        $ops[] = [$start, $end, '\\'.$target];
    }

    /**
     * Decides the import (or alias) of every name that needs one; returns the `use` lines to insert.
     *
     * @param  array<string, list<Name>>     $needs
     * @param  array<string, string>         $imports  extended by the imports it decides
     * @param  array<string, string>         $declared lower short name => final FQCN of the types declared in the file
     * @param  list<array{int, int, string}> $ops
     * @param  list<string>                  $warnings
     * @return list<string>
     */
    private function imports(array $needs, array &$imports, array $declared, array &$ops, array &$warnings, string $file): array
    {
        ksort($needs, SORT_STRING);
        $lines = [];
        foreach ($needs as $target => $names) {
            $short = self::lastSegment($target);
            $key = strtolower($short);
            $clash = (isset($imports[$key]) && $imports[$key] !== $target) || (isset($declared[$key]) && $declared[$key] !== $target);
            $alias = null;
            if ($clash) {
                $alias = $short.'As'.($this->package === null ? 'Core' : ($this->package)($target));
                $aliasKey = strtolower($alias);
                if (isset($imports[$aliasKey]) && $imports[$aliasKey] !== $target || isset($declared[$aliasKey])) {
                    throw new MoveUsage("{$file}: the alias {$alias} for {$target} is taken");
                }
                $warnings[] = "{$file}: {$target} is imported as {$alias} (the short name {$short} is taken)";
                $key = $aliasKey;
                foreach ($names as $name) {
                    $ops[] = [$name->getStartFilePos(), $name->getEndFilePos() + 1, $alias];
                }
            }
            if (isset($imports[$key]) && $imports[$key] === $target) {
                continue;
            }
            $imports[$key] = $target;
            $lines[] = 'use '.$target.($alias !== null ? ' as '.$alias : '').';';
        }
        sort($lines, SORT_STRING);

        return $lines;
    }

    /**
     * @param  list<string>            $lines
     * @param  array<int, mixed>       $tokens
     * @return array{int, int, string}
     */
    private function insertion(Collector $collector, array $tokens, string $code, array $lines): array
    {
        $last = $collector->uses === [] ? null : end($collector->uses);
        if ($last !== null) {
            $at = $last->getEndFilePos() + 1;

            return [$at, $at, "\n".implode("\n", $lines)];
        }
        $namespace = $collector->namespaces[0] ?? null;
        if ($namespace?->name !== null) {
            $semicolon = strpos($code, ';', $namespace->name->getEndFilePos());
            if ($semicolon !== false) {
                return [$semicolon + 1, $semicolon + 1, "\n\n".implode("\n", $lines)];
            }
        }
        if ($collector->declare !== null) {
            $at = $collector->declare->getEndFilePos() + 1;

            return [$at, $at, "\n\n".implode("\n", $lines)];
        }
        $open = $tokens[0] ?? null;
        $at = $open === null ? 0 : $open->pos + strlen($open->text);

        return [$at, $at, "\n\n".implode("\n", $lines)."\n"];
    }

    /**
     * @param list<array{int, int, string}> $ops
     *
     * @throws MoveFailure
     */
    private static function splice(string $file, string $code, array $ops): string
    {
        usort($ops, static fn (array $a, array $b): int => $b[0] <=> $a[0] ?: $b[1] <=> $a[1]);
        $previous = PHP_INT_MAX;
        foreach ($ops as [$start, $end, $replacement]) {
            if ($end > $previous) {
                throw new MoveFailure("{$file}: two edits overlap at offset {$start}");
            }
            $code = substr($code, 0, $start).$replacement.substr($code, $end);
            $previous = $start;
        }

        return $code;
    }

    private static function namespaceOf(string $fqcn): string
    {
        $at = strrpos($fqcn, '\\');

        return $at === false ? '' : substr($fqcn, 0, $at);
    }

    private static function lastSegment(string $fqcn): string
    {
        $at = strrpos($fqcn, '\\');

        return $at === false ? $fqcn : substr($fqcn, $at + 1);
    }
}

/**
 * Collects what the rewrite needs from the resolved tree: namespaces, imports, the class-like types a file declares
 * and every class name (the names of function calls and constants are not classes and are skipped).
 *
 * @internal
 */
final class Collector extends NodeVisitorAbstract
{
    /** @var list<Stmt\Namespace_> */
    public array $namespaces = [];

    /** @var list<Stmt\Use_|Stmt\GroupUse> */
    public array $uses = [];

    /** @var array<string, string> short name => FQCN of the declared types */
    public array $declared = [];

    /** @var list<Name> */
    public array $names = [];

    public ?Stmt\Declare_ $declare = null;

    private string $namespace = '';

    /** @var array<int, true> */
    private array $skip = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespaces[] = $node;
            $this->namespace = $node->name?->toString() ?? '';
        } elseif ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
            $this->uses[] = $node;
        } elseif ($node instanceof Stmt\Declare_ && $this->declare === null) {
            $this->declare = $node;
        } elseif ($node instanceof Stmt\ClassLike && $node->name !== null) {
            $this->declared[$node->name->toString()] = ($this->namespace === '' ? '' : $this->namespace.'\\').$node->name->toString();
        } elseif (($node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\ConstFetch) && $node->name instanceof Name) {
            $this->skip[spl_object_id($node->name)] = true;
        } elseif ($node instanceof Name && !isset($this->skip[spl_object_id($node)])) {
            $this->names[] = $node;
        }

        return null;
    }
}
