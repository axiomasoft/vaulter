<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Identities;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;
use Vaulter\Inventory\AstFiles;
use Vaulter\Inventory\Identities;
use Vaulter\Inventory\IdentityOwners;
use Vaulter\Inventory\Repo;
use Vaulter\Inventory\SourceIndex;

require_once dirname(__DIR__).'/Identities.php';
require_once __DIR__.'/Config.php';

/**
 * identities/protocol.json: what travels between processes or sits in stored data under a name — event types with
 * their payload keys and sinks, problem codes with HTTP status, morph usages, class names serialized into config,
 * queues and casts, and the hand-written formats (CAS key, event id, ETag, cursor, headers, JWT claims) whose
 * implementing symbol must exist.
 *
 * @phpstan-import-type Built from Identities
 * @phpstan-import-type Problem from Identities
 */
final class Protocol
{
    private const PROBLEM_SOURCE = 'Vaulter\Http\Problems\ProblemCodeSource';

    private const EVENT_SPEC = 'Vaulter\Attributes\EventSpec';

    private const SHOULD_QUEUE = 'Illuminate\Contracts\Queue\ShouldQueue';

    private const MORPH_CALLS = ['morphMap', 'enforceMorphMap', 'requiresMorphMap', 'getMorphClass', 'morphTo', 'morphOne', 'morphMany', 'morphToMany', 'morphedByMany'];

    private const FQCN = '/^Vaulter\\\\[A-Za-z0-9_\\\\]+$/';

    /** @return Built */
    public static function build(Repo $repo): array
    {
        $index = SourceIndex::forRepo($repo);
        $items = [];
        $problems = [];

        /** @var array<string, array<string, mixed>> $morph */
        $morph = [];
        $finder = new NodeFinder;

        foreach ($index->declarations() as $declaration) {
            if (in_array(self::SHOULD_QUEUE, $declaration['implements'], true)) {
                $items[] = ['id' => 'serialized:queue:'.$declaration['fqcn'], 'source' => ['file' => $declaration['file'], 'symbol' => $declaration['fqcn']], 'class' => $declaration['fqcn'], 'via' => 'ShouldQueue'];
            }
        }

        $files = [];
        foreach (SourceIndex::PACKAGES as $package) {
            array_push($files, ...AstFiles::tree($repo, "packages/{$package}/src"));
        }
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            AstFiles::walk(AstFiles::parse($repo, $file), static function (Node $node, string $symbol) use (&$items, &$morph, $file, $index, $finder): void {
                if ($node instanceof Node\Stmt\EnumCase) {
                    $item = self::event($node, $symbol, $file);
                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
                if (($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) && $node->name instanceof Node\Identifier && in_array($node->name->toString(), self::MORPH_CALLS, true)) {
                    $key = 'morph:'.$symbol.'#'.$node->name->toString();
                    $morph[$key] ??= ['id' => $key, 'source' => ['file' => $file, 'symbol' => $symbol], 'call' => $node->name->toString(), 'calls' => 0];
                    $morph[$key]['calls']++;
                }
                if ($node instanceof Node\Stmt\ClassMethod) {
                    $class = explode('::', $symbol)[0];
                    $declaration = $index->declaration($class);
                    if ($declaration !== null && in_array(self::PROBLEM_SOURCE, $declaration['implements'], true) && $node->name->toString() === 'codes') {
                        array_push($items, ...self::problems($node, $class, $file, $finder));
                    }
                    if ($node->name->toString() === 'casts' && $declaration !== null && $index->isEloquent($class)) {
                        array_push($items, ...self::casts($node, $class, $file, $finder));
                    }
                }
            });
        }

        ksort($morph, SORT_STRING);
        array_push($items, ...array_values($morph));

        foreach (Config::defaults($repo) as $path => $default) {
            $fqcns = array_values(array_filter(is_array($default['value']) ? $default['value'] : [$default['value']], static fn (mixed $v): bool => is_string($v) && preg_match(self::FQCN, $v) === 1));
            if ($fqcns !== []) {
                $items[] = ['id' => 'serialized:config:'.$path, 'source' => ['file' => $default['file'], 'symbol' => '<file>'], 'config_key' => $path, 'fqcns' => $fqcns];
            }
        }

        $owners = IdentityOwners::load($repo, 'protocol');
        foreach ($owners->formats() as $format) {
            $problem = self::checkFormat($index, $repo, $format);
            if ($problem !== null) {
                $problems[] = $problem;

                continue;
            }
            /** @var array{id: string, source: array{file: string, symbol: string}, format: string, why: string} $format */
            $items[] = ['id' => $format['id'], 'source' => $format['source'], 'format' => $format['format'], 'manual' => true];
        }

        return Identities::assemble('protocol', $items, $owners, [], $problems);
    }

    /** @return array<string, mixed>|null */
    private static function event(Node\Stmt\EnumCase $case, string $symbol, string $file): ?array
    {
        foreach ($case->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->toString() !== self::EVENT_SPEC || !$case->expr instanceof Scalar\String_) {
                    continue;
                }
                $spec = [];
                foreach ($attr->args as $position => $arg) {
                    $name = $arg->name?->toString() ?? ['subject', 'data', 'activity', 'external', 'journal', 'description'][$position] ?? (string) $position;
                    $spec[$name] = Config::evaluate($arg->value);
                }

                return [
                    'id' => 'event:'.$case->expr->value,
                    'source' => ['file' => $file, 'symbol' => $symbol.'::'.$case->name->toString()],
                    'case' => $case->name->toString(),
                    'subject' => $spec['subject'] ?? null,
                    'data' => $spec['data'] ?? [],
                    'sinks' => ['activity' => $spec['activity'] ?? null, 'external' => $spec['external'] ?? null, 'journal' => $spec['journal'] ?? null],
                ];
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private static function problems(Node\Stmt\ClassMethod $method, string $class, string $file, NodeFinder $finder): array
    {
        $return = $finder->findFirstInstanceOf($method->stmts ?? [], Node\Stmt\Return_::class);
        $items = [];
        if (!$return instanceof Node\Stmt\Return_ || !$return->expr instanceof Expr\Array_) {
            return [];
        }
        foreach ($return->expr->items as $entry) {
            if (!$entry->key instanceof Scalar\String_) {
                continue;
            }
            $spec = self::problemSpec($entry->value);
            $items[] = [
                'id' => 'problem:'.$entry->key->value,
                'source' => ['file' => $file, 'symbol' => $class.'::codes'],
                'status' => $spec['status'],
                'exception' => $spec['exception'],
                'retryable' => $spec['retryable'],
                'receipt' => $spec['receipt'],
            ];
        }

        return $items;
    }

    /**
     * Reads `new ProblemSpec(status, exception, retryable, receipt)` with positional or named arguments; an
     * omitted retryable or receipt is the constructor default `never`. Anything else yields nulls, which the
     * fixture then shows.
     *
     * @return array{status: mixed, exception: mixed, retryable: string|null, receipt: string|null}
     */
    private static function problemSpec(Expr $value): array
    {
        $spec = ['status' => null, 'exception' => null, 'retryable' => null, 'receipt' => null];
        if (!$value instanceof Expr\New_ || !$value->class instanceof Node\Name || $value->class->getLast() !== 'ProblemSpec') {
            return $spec;
        }
        $spec['retryable'] = $spec['receipt'] = 'never';
        $positions = ['status', 'exception', 'retryable', 'receipt'];
        foreach ($value->args as $position => $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            $name = $arg->name?->toString() ?? $positions[$position] ?? null;
            if ($name === null || !array_key_exists($name, $spec)) {
                continue;
            }
            $spec[$name] = in_array($name, ['retryable', 'receipt'], true) ? self::enumCase($arg->value) : Config::evaluate($arg->value);
        }

        return $spec;
    }

    /** `Retryable::AfterRefetch` → `after_refetch`; the backed values are the snake case of the case names. */
    private static function enumCase(Expr $value): ?string
    {
        if (!$value instanceof Expr\ClassConstFetch || !$value->name instanceof Node\Identifier) {
            return null;
        }

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value->name->toString()));
    }

    /** @return list<array<string, mixed>> */
    private static function casts(Node\Stmt\ClassMethod $method, string $class, string $file, NodeFinder $finder): array
    {
        $return = $finder->findFirstInstanceOf($method->stmts ?? [], Node\Stmt\Return_::class);
        $items = [];
        if (!$return instanceof Node\Stmt\Return_ || !$return->expr instanceof Expr\Array_) {
            return [];
        }
        foreach ($return->expr->items as $entry) {
            if (!$entry->key instanceof Scalar\String_ || !$entry->value instanceof Expr\ClassConstFetch) {
                continue;
            }
            $cast = Config::evaluate($entry->value);
            if (is_string($cast)) {
                $items[] = ['id' => 'serialized:cast:'.$class.'.'.$entry->key->value, 'source' => ['file' => $file, 'symbol' => $class.'::casts'], 'model' => $class, 'attribute' => $entry->key->value, 'cast' => $cast];
            }
        }

        return $items;
    }

    /**
     * A hand-written format must say what it is, who owns it and where it lives; the symbol must exist.
     *
     * @param  array<string, mixed> $format
     * @return Problem|null
     */
    public static function checkFormat(SourceIndex $index, Repo $repo, array $format): ?array
    {
        $id = is_string($format['id'] ?? null) ? $format['id'] : '(no id)';
        $source = $format['source'] ?? null;
        if (!is_string($format['id'] ?? null) || !str_starts_with($format['id'], 'format:') || !is_string($format['format'] ?? null) || !is_array($source)
            || !is_string($source['file'] ?? null) || !is_string($source['symbol'] ?? null)) {
            return ['code' => 'invalid-format', 'message' => "protocol format {$id}: id (format:…), format and source {file, symbol} are required", 'exit' => 2];
        }
        if (!$repo->exists($source['file'])) {
            return ['code' => 'format-source', 'message' => "protocol format {$id}: file {$source['file']} does not exist", 'exit' => 2];
        }
        [$class, $member] = array_pad(explode('::', $source['symbol'], 2), 2, null);
        $declaration = $index->declaration($class);
        if ($declaration === null || $declaration['file'] !== $source['file']) {
            return ['code' => 'format-source', 'message' => "protocol format {$id}: {$class} is not declared in {$source['file']}", 'exit' => 2];
        }
        if ($member !== null) {
            $methods = array_column($declaration['methods'], 'name');
            $isConstant = preg_match('/\bconst\s+(?:[\w\\\\?]+\s+)?'.preg_quote($member, '/').'\b/', $repo->read($source['file'])) === 1;
            if (!in_array($member, $methods, true) && !$isConstant) {
                return ['code' => 'format-source', 'message' => "protocol format {$id}: {$class} has no method or constant {$member}", 'exit' => 2];
            }
        }

        return null;
    }
}
