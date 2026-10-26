<?php

declare(strict_types=1);

namespace Vaulter\Inventory;

use Closure;
use LogicException;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Throwable;

require_once __DIR__.'/TargetMap.php';

/**
 * A finding the builder of one identities file cannot ignore. `exit` is the CLI code it maps to: 2 for a rule error
 * (an identity without an owner rule, a stale rule, a malformed owners file), 1 when the source data itself drifts
 * (a table that differs between presets in an unexpected way).
 *
 * @phpstan-type Problem array{code: string, message: string, exit: int}
 * @phpstan-type Source array{file: string, symbol: string}
 * @phpstan-type Item array<string, mixed>
 * @phpstan-type Built array{data: array<string, mixed>, problems: list<Problem>}
 */
final class Identities
{
    public const KINDS = ['schema', 'config', 'cli', 'http', 'protocol'];

    public const DIR = 'tests/Repo/Arch/Fixtures/identities';

    public const BASE = '35718fd2';

    /** The hand-written ledger of identity changes since BASE (P3.2): one record per changed identity. */
    public const LEDGER = self::DIR.'/changes.json';

    public const CHANGES = ['added', 'removed', 'renamed', 'redefined'];

    public static function fixture(string $kind): string
    {
        return self::DIR.'/'.$kind.'.json';
    }

    public static function ownersFile(string $kind): string
    {
        return self::DIR.'/owners/'.$kind.'.json';
    }

    /**
     * Assigns owners, sorts by id and wraps the items in the file format.
     * An item is `{id, source: {file, symbol}, …}`; its owner is looked up by `_owner_key` (default: the id), which
     * lets a column, an index or an environment variable follow the owner of the thing that carries it.
     *
     * @param  list<Item>           $items
     * @param  array<string, mixed> $extra    top-level fields of the file besides version/base/kind/changes/items
     * @param  list<Problem>        $problems problems found while extracting
     * @return Built
     */
    public static function assemble(string $kind, array $items, IdentityOwners $owners, array $extra = [], array $problems = []): array
    {
        $problems = [...$owners->problems(), ...$problems];
        $seen = [];
        $done = [];
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            $source = $item['source'] ?? null;
            if (!is_string($id) || $id === '' || !is_array($source) || !is_string($source['file'] ?? null) || !is_string($source['symbol'] ?? null)) {
                throw new LogicException("{$kind}: every identity needs an id and a source {file, symbol}");
            }
            if (isset($seen[$id])) {
                $problems[] = ['code' => 'duplicate-id', 'message' => "{$kind}: identity {$id} is produced twice (by {$seen[$id]} and {$source['file']})", 'exit' => 2];

                continue;
            }
            $seen[$id] = $source['file'];

            $key = $item['_owner_key'] ?? $id;
            unset($item['_owner_key']);
            $winner = is_string($key) ? $owners->resolve($key) : null;
            if ($winner === null) {
                $problems[] = ['code' => 'unclassified', 'message' => "{$kind}: {$id}: no owner rule matches ".(is_string($key) ? $key : $id).' — add one to '.self::ownersFile($kind), 'exit' => 2];

                continue;
            }
            $item['owner'] = $winner;
            $done[$id] = $item;
        }
        ksort($done, SORT_STRING);
        $problems = [...$problems, ...$owners->stale(), ...self::checkChanges($kind, $owners->changes(), $done)];

        return ['data' => ['version' => 1, 'base' => self::BASE, 'kind' => $kind, 'changes' => $owners->changes(), ...$extra, 'items' => array_values($done)], 'problems' => $problems];
    }

    /**
     * The ledger records of one kind, in file order: `{kind, id, change, from?, reason, decision}`. A missing ledger
     * file is an empty ledger.
     *
     * @return list<array<string, mixed>>
     */
    public static function ledger(Repo $repo, string $kind): array
    {
        if (!$repo->exists(self::LEDGER)) {
            return [];
        }
        $changes = $repo->readJson(self::LEDGER)['changes'] ?? null;
        if (!is_array($changes) || !array_is_list($changes)) {
            throw new InventoryError(self::LEDGER.': "changes" must be a list');
        }
        $records = [];
        foreach ($changes as $i => $change) {
            if (!is_array($change)) {
                throw new InventoryError(self::LEDGER.": change #{$i} must be an object");
            }
            if (($change['kind'] ?? null) === $kind) {
                /** @var array<string, mixed> $change */
                $records[] = $change;
            }
        }

        return $records;
    }

    /**
     * A ledger record must name an existing identity for added/redefined/renamed, a gone one for removed and for the
     * old id of a rename; the reason and a decision `D<n>` or `PLAN<n>-D<n>` are required.
     *
     * @param  list<array<string, mixed>> $changes
     * @param  array<string, mixed>       $items   id => item
     * @return list<Problem>
     */
    public static function checkChanges(string $kind, array $changes, array $items): array
    {
        $problems = [];
        foreach ($changes as $change) {
            $id = $change['id'] ?? null;
            $type = $change['change'] ?? null;
            $label = $kind.' ledger: '.(is_string($id) ? $id : '?');
            if (!is_string($id) || $id === '' || !in_array($type, self::CHANGES, true) || !is_string($change['reason'] ?? null) || trim($change['reason']) === '' || !is_string($change['decision'] ?? null) || preg_match('/^(?:PLAN[1-9]\d*-)?D[1-9]\d*$/', $change['decision']) !== 1) {
                $problems[] = ['code' => 'invalid-change', 'message' => "{$label}: needs id, change (".implode('|', self::CHANGES).'), reason and decision D<n> or PLAN<n>-D<n>', 'exit' => 2];

                continue;
            }
            $exists = isset($items[$id]);
            $from = $change['from'] ?? null;
            $wrong = match ($type) {
                'added', 'redefined' => $exists ? null : 'names an identity that does not exist',
                'removed' => $exists ? 'names an identity that still exists' : null,
                default => !$exists ? 'names an identity that does not exist' : (!is_string($from) || $from === '' ? 'has no "from"' : (isset($items[$from]) ? 'renames from an identity that still exists' : null)),
            };
            if ($wrong !== null) {
                $problems[] = ['code' => 'stale-change', 'message' => "{$label} ({$type}) {$wrong}", 'exit' => 1];
            }
        }

        return $problems;
    }

    /**
     * FQCN => owner of the type-map (target-map.json): identities read it, never the other way round.
     *
     * @return array<string, string>
     */
    public static function classOwners(Repo $repo): array
    {
        $owners = [];
        $types = $repo->readJson(TargetMap::FIXTURE)['types'] ?? [];
        foreach (is_array($types) ? $types : [] as $type) {
            if (is_array($type) && is_string($type['fqcn'] ?? null) && is_string($type['owner'] ?? null)) {
                $owners[$type['fqcn']] = $type['owner'];
            }
        }

        return $owners;
    }

    /**
     * Adds `class_owner` to every item whose `$field` class is owned by someone else in the type-map than the item
     * is by its rule: a finding for P2.5, never an error here.
     *
     * @param  Built $built
     * @return Built
     */
    public static function withClassOwners(array $built, Repo $repo, string $field): array
    {
        $classOwners = self::classOwners($repo);
        foreach ($built['data']['items'] as $i => $item) {
            $class = $item[$field] ?? null;
            if (is_string($class) && isset($classOwners[$class]) && $classOwners[$class] !== $item['owner']) {
                $built['data']['items'][$i]['class_owner'] = $classOwners[$class];
            }
        }

        return $built;
    }

    /**
     * @param  list<Problem> $problems
     * @return list<string>
     */
    public static function describe(array $problems): array
    {
        return array_map(static fn (array $p): string => sprintf('[%s] %s', $p['code'], $p['message']), $problems);
    }

    /** @param list<Problem> $problems */
    public static function exitCode(array $problems): int
    {
        $exits = array_column($problems, 'exit');

        return $exits === [] ? 0 : (in_array(2, $exits, true) ? 2 : 1);
    }
}

/**
 * The hand-written ownership rules of one identities kind (`identities/owners/<kind>.json`): `match` ∈ exact|prefix|
 * glob, `value`, `owner`, `why`. Priority exact > the longest prefix > the longest glob. A rule that never wins is
 * stale; an identity no rule matches is an error. The protocol file also carries `formats`: hand-written exact
 * identities (CAS key, ETag, JWT claims…) with the symbol that implements them.
 */
final class IdentityOwners
{
    public const MATCHES = ['exact', 'prefix', 'glob'];

    /** @var list<array{id: string, rule: array<string, mixed>}> */
    private array $rules = [];

    /** @var list<array<string, mixed>> */
    private array $formats = [];

    /** @var list<array{code: string, message: string, exit: int}> */
    private array $problems = [];

    /** @var array<string, true> */
    private array $won = [];

    /** @var list<array<string, mixed>> the ledger records of this kind */
    private array $changes = [];

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $owners
     */
    private function __construct(private readonly string $kind, array $data, array $owners)
    {
        $rules = $data['rules'] ?? null;
        if (!is_array($rules) || !array_is_list($rules)) {
            throw new InventoryError(Identities::ownersFile($kind).': "rules" must be a list');
        }
        $seen = [];
        foreach ($rules as $i => $rule) {
            if (!is_array($rule) || !$this->valid($i, $rule, $owners)) {
                continue;
            }
            /** @var array<string, mixed> $rule */
            $id = $rule['match'].':'.$rule['value'];
            if (isset($seen[$id])) {
                $this->problems[] = ['code' => 'conflict', 'message' => "{$kind} owners: rule {$id} is declared twice", 'exit' => 2];

                continue;
            }
            $seen[$id] = true;
            $this->rules[] = ['id' => $id, 'rule' => $rule];
        }

        $formats = $data['formats'] ?? [];
        if (!is_array($formats) || !array_is_list($formats)) {
            throw new InventoryError(Identities::ownersFile($kind).': "formats" must be a list');
        }
        foreach ($formats as $i => $format) {
            if (!is_array($format)) {
                throw new InventoryError(Identities::ownersFile($kind).": format #{$i} must be an object");
            }
            /** @var array<string, mixed> $format */
            $this->formats[] = $format;
            // a format names its owner itself: it classifies exactly its own id
            if (is_string($format['id'] ?? null) && $this->valid($i, ['match' => 'exact', 'value' => $format['id'], 'owner' => $format['owner'] ?? null, 'why' => $format['why'] ?? null], $owners)) {
                $this->rules[] = ['id' => 'exact:'.$format['id'], 'rule' => ['match' => 'exact', 'value' => $format['id'], 'owner' => $format['owner'], 'why' => $format['why']]];
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $owners
     */
    public static function fromArray(string $kind, array $data, array $owners = TargetMap::OWNERS): self
    {
        return new self($kind, $data, $owners);
    }

    public static function load(Repo $repo, string $kind): self
    {
        $owners = new self($kind, $repo->readJson(Identities::ownersFile($kind)), TargetMap::OWNERS);
        $owners->changes = Identities::ledger($repo, $kind);

        return $owners;
    }

    /** @return list<array<string, mixed>> */
    public function changes(): array
    {
        return $this->changes;
    }

    /** The owner of the winning rule for a key; marks the rule as used. */
    public function resolve(string $key): ?string
    {
        $best = null;
        $bestRank = [-1, -1];
        foreach ($this->rules as $item) {
            $value = $item['rule']['value'];
            $match = $item['rule']['match'];
            $rank = match ($match) {
                'exact' => $key === $value ? [3, PHP_INT_MAX] : [-1, -1],
                'prefix' => str_starts_with($key, $value) ? [2, strlen($value)] : [-1, -1],
                default => fnmatch($value, $key) ? [1, strlen($value)] : [-1, -1],
            };
            if ($rank > $bestRank) {
                $best = $item;
                $bestRank = $rank;
            }
        }
        if ($best === null) {
            return null;
        }
        $this->won[$best['id']] = true;

        return $best['rule']['owner'];
    }

    /** @return list<array<string, mixed>> */
    public function formats(): array
    {
        return $this->formats;
    }

    /** @return list<array{code: string, message: string, exit: int}> */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The rules no identity matched: call after every identity was resolved.
     *
     * @return list<array{code: string, message: string, exit: int}>
     */
    public function stale(): array
    {
        $stale = [];
        foreach ($this->rules as $item) {
            if (!isset($this->won[$item['id']])) {
                $stale[] = ['code' => 'stale-rule', 'message' => "{$this->kind} owners: rule {$item['id']} classifies nothing", 'exit' => 2];
            }
        }

        return $stale;
    }

    /**
     * @param array<array-key, mixed> $rule
     * @param list<string>            $owners
     */
    private function valid(int $index, array $rule, array $owners): bool
    {
        foreach (['match', 'value', 'owner', 'why'] as $field) {
            if (!is_string($rule[$field] ?? null) || trim($rule[$field]) === '') {
                $this->problems[] = ['code' => 'invalid-rule', 'message' => "{$this->kind} owners #{$index}: \"{$field}\" is required", 'exit' => 2];

                return false;
            }
        }
        if (!in_array($rule['match'], self::MATCHES, true)) {
            $this->problems[] = ['code' => 'invalid-rule', 'message' => "{$this->kind} owners #{$index}: match must be one of ".implode(', ', self::MATCHES), 'exit' => 2];

            return false;
        }
        if (!in_array($rule['owner'], $owners, true)) {
            $this->problems[] = ['code' => 'invalid-rule', 'message' => "{$this->kind} owners #{$index}: unknown owner \"{$rule['owner']}\"", 'exit' => 2];

            return false;
        }

        return true;
    }
}

/** identities-<kind>: the generator of one fixture; the builder is the kind's `build(Repo): Built`. */
final class IdentityGenerator implements Generator
{
    /** @param Closure(Repo): array{data: array<string, mixed>, problems: list<array{code: string, message: string, exit: int}>} $builder */
    public function __construct(private readonly string $kind, private readonly Closure $builder) {}

    public function name(): string
    {
        return 'identities-'.$this->kind;
    }

    public function write(Repo $repo, array $options = []): array
    {
        $built = ($this->builder)($repo);
        if ($built['problems'] !== []) {
            throw new InventoryError(implode("\n", Identities::describe($built['problems']))."\nfix the owners in ".Identities::ownersFile($this->kind).' or the source data');
        }
        Json::write($repo, Identities::fixture($this->kind), $built['data']);

        return [Identities::fixture($this->kind)];
    }

    public function check(Repo $repo, array $options = []): array
    {
        return $this->judge($repo, false);
    }

    public function report(Repo $repo, array $options = []): array
    {
        return $this->judge($repo, true);
    }

    /** @return array{code: int, lines: list<string>} */
    private function judge(Repo $repo, bool $report): array
    {
        try {
            $built = ($this->builder)($repo);
        } catch (InventoryError $e) {
            return ['code' => 2, 'lines' => [$e->getMessage()]];
        }

        if ($built['problems'] !== []) {
            return ['code' => Identities::exitCode($built['problems']), 'lines' => [...Identities::describe($built['problems']), 'fix the owners in '.Identities::ownersFile($this->kind).' or the source data']];
        }

        $path = Identities::fixture($this->kind);
        $diff = Diff::unified($repo->exists($path) ? $repo->read($path) : '', Json::encode($built['data']), $path);
        $lines = $report ? $this->summary($built['data']) : [];
        if ($diff !== []) {
            return ['code' => 1, 'lines' => [...$lines, ...$diff, "fix: scripts/php84 php scripts/inventory/inventory.php {$this->name()} --write"]];
        }

        return ['code' => 0, 'lines' => $lines];
    }

    /**
     * @param  array<string, mixed> $data
     * @return list<string>
     */
    private function summary(array $data): array
    {
        $counts = [];
        /** @var list<array<string, mixed>> $items */
        $items = $data['items'];
        foreach ($items as $item) {
            $type = explode(':', (string) $item['id'], 2)[0];
            $key = sprintf('%s %s', $type, $item['owner']);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);
        $lines = [sprintf('%s: %d identities', $this->kind, count($items))];
        foreach ($counts as $key => $n) {
            $lines[] = sprintf('%5d  %s', $n, $key);
        }

        return $lines;
    }
}

/** Parsed (and name-resolved) PHP files of the repository, cached per process; shared by the extractors. */
final class AstFiles
{
    /** @var array<string, list<Node\Stmt>> */
    private static array $cache = [];

    /** @return list<Node\Stmt> */
    public static function parse(Repo $repo, string $relative): array
    {
        $key = $repo->root().'|'.$relative;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($repo->read($relative)) ?? [];
        } catch (Throwable $e) {
            throw new InventoryError("{$relative}: {$e->getMessage()}", 0, $e);
        }
        $traverser = new NodeTraverser(new NameResolver);

        /** @var list<Node\Stmt> $resolved */
        $resolved = $traverser->traverse($ast);

        return self::$cache[$key] = $resolved;
    }

    /**
     * Repo-relative PHP files of a package directory, sorted.
     *
     * @return list<string>
     */
    public static function files(Repo $repo, string $glob): array
    {
        $files = [];
        $pattern = $repo->path($glob);
        foreach (glob($pattern) ?: [] as $path) {
            $files[] = substr($path, strlen($repo->root()) + 1);
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Every PHP file of packages/<package>/<root>/**, sorted.
     *
     * @return list<string>
     */
    public static function tree(Repo $repo, string $dir): array
    {
        $base = $repo->path($dir);
        if (!is_dir($base)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($repo->root()) + 1);
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Visits every node of a parsed file with its symbol (`Class::method`, `Class` or `<file>`).
     *
     * @param list<Node\Stmt>              $stmts
     * @param callable(Node, string): void $visit
     */
    public static function walk(array $stmts, callable $visit): void
    {
        $traverser = new NodeTraverser(new SymbolVisitor($visit));
        $traverser->traverse($stmts);
    }
}

/** @internal tracks the enclosing class and method while a file is walked */
final class SymbolVisitor extends NodeVisitorAbstract
{
    /** @var list<string> */
    private array $classes = [];

    /** @var list<?string> */
    private array $methods = [];

    /** @param callable(Node, string): void $visit */
    public function __construct(private $visit) {}

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassLike && $node->namespacedName !== null) {
            $this->classes[] = $node->namespacedName->toString();
            $this->methods[] = null;
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $this->methods[count($this->methods) - 1] = $node->name->toString();
        }
        $class = $this->classes === [] ? null : $this->classes[count($this->classes) - 1];
        $method = $this->methods === [] ? null : $this->methods[count($this->methods) - 1];
        ($this->visit)($node, $class === null ? '<file>' : ($method === null ? $class : "{$class}::{$method}"));

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->methods[count($this->methods) - 1] = null;
        } elseif ($node instanceof Node\Stmt\ClassLike && $node->namespacedName !== null) {
            array_pop($this->classes);
            array_pop($this->methods);
        }

        return null;
    }
}
