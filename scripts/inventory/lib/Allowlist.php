<?php

declare(strict_types=1);

namespace Vaulter\Inventory;

/**
 * An exact allowlist of known violations. found − allowed is new, allowed − found (or a different count) is stale;
 * both are red, so the list can neither grow silently nor keep an entry that no longer exists. A list in mode
 * `block` keeps only the entries that may outlive the blocking phase.
 *
 * @phpstan-type Violation array{invariant: string, view: string, file: string, symbol: string, kind: string, target: string, target_owner?: string, count?: int}
 * @phpstan-type Entry array{view: string, file: string, symbol: string, kind: string, target: string, count: int, reason: string, removed_in: string, category?: string}
 * @phpstan-type Comparison array{new: list<array{key: string, count: int}>, stale: list<array{key: string, expected: int, found: int}>}
 */
final class Allowlist
{
    public const KEY_FIELDS = ['view', 'file', 'symbol', 'kind', 'target'];

    public const REMOVED_IN = ['P3', 'P4', 'P5', 'never'];

    /** The only entries that may stay forever: the named destinations I4 itself demands. */
    public const PERMANENT_CATEGORIES = ['config-validation', 'host-lifecycle', 'explicit-adapter'];

    public const MODES = ['report', 'block'];

    /** I4 places each allowed probe in one of these categories. */
    public const I4_CATEGORIES = ['activation', 'jwt-optional', 'explicit-adapter', 'config-validation', 'host-lifecycle'];

    /** The phase from which an invariant blocks (architecture#invariants); an entry must be gone by then. */
    public const BLOCKS_FROM = ['I1' => 'P4', 'I2' => 'P4', 'I3' => 'P4', 'I4' => 'P3'];

    /** Allowed to outlive the blocking phase: jwt-optional is removed when P5 makes collaboration a require. */
    public const LATE_CATEGORIES = ['jwt-optional'];

    /** @var array<string, array{count: int, entry: Entry}> */
    private array $entries = [];

    /** @param  list<array<string, mixed>>  $entries */
    private function __construct(public readonly string $invariant, public readonly string $mode, array $entries)
    {
        foreach ($entries as $i => $raw) {
            $entry = $this->validate($raw, $i);
            $key = self::key($entry);
            if (isset($this->entries[$key])) {
                throw new InventoryError("{$invariant} allowlist: duplicate entry {$key}");
            }
            $this->entries[$key] = ['count' => $entry['count'], 'entry' => $entry];
        }
        if ($mode === 'block') {
            // A blocking list keeps only what may outlive the blocking phase: permanent entries and late categories.
            foreach ($this->entries as $key => $item) {
                $entry = $item['entry'];
                if ($entry['removed_in'] !== 'never' && !in_array($entry['category'] ?? null, self::LATE_CATEGORIES, true)) {
                    throw new InventoryError("{$invariant} allowlist: mode \"block\" admits only \"never\" and ".implode(', ', self::LATE_CATEGORIES).' entries, found '.$key);
                }
            }
        }
    }

    public static function load(Repo $repo, string $relative): self
    {
        return self::fromArray($repo->readJson($relative), $relative);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data, string $what = 'allowlist'): self
    {
        $invariant = $data['invariant'] ?? null;
        $mode = $data['mode'] ?? null;
        $entries = $data['entries'] ?? null;
        if (!is_string($invariant) || $invariant === '') {
            throw new InventoryError("{$what}: \"invariant\" is required");
        }
        if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
            throw new InventoryError("{$what}: \"mode\" must be one of ".implode(', ', self::MODES));
        }
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new InventoryError("{$what}: \"entries\" must be a list");
        }

        /** @var list<array<string, mixed>> $entries */
        return new self($invariant, $mode, $entries);
    }

    /**
     * @param  list<Violation> $found one item per occurrence or already counted
     * @return Comparison
     */
    public function compare(array $found): array
    {
        $counts = [];
        foreach ($found as $violation) {
            $key = self::key($violation);
            $counts[$key] = ($counts[$key] ?? 0) + ($violation['count'] ?? 1);
        }
        ksort($counts, SORT_STRING);

        $new = [];
        foreach ($counts as $key => $count) {
            if (!isset($this->entries[$key])) {
                $new[] = ['key' => $key, 'count' => $count];
            }
        }

        $stale = [];
        $allowed = $this->entries;
        ksort($allowed, SORT_STRING);
        foreach ($allowed as $key => $item) {
            $actual = $counts[$key] ?? 0;
            if ($actual !== $item['count']) {
                $stale[] = ['key' => $key, 'expected' => $item['count'], 'found' => $actual];
            }
        }

        return ['new' => $new, 'stale' => $stale];
    }

    /**
     * Human lines for a comparison; empty when nothing is new or stale.
     *
     * @param  Comparison   $comparison
     * @return list<string>
     */
    public static function describe(array $comparison): array
    {
        $lines = [];
        foreach ($comparison['new'] as $new) {
            $lines[] = sprintf('new   %s (x%d)', $new['key'], $new['count']);
        }
        foreach ($comparison['stale'] as $stale) {
            $lines[] = sprintf('stale %s (allowed x%d, found x%d)', $stale['key'], $stale['expected'], $stale['found']);
        }

        return $lines;
    }

    /** @return list<Entry> */
    public function entries(): array
    {
        return array_values(array_map(static fn (array $item): array => $item['entry'], $this->entries));
    }

    /** @param  array<string, mixed>  $violation */
    public static function key(array $violation): string
    {
        $parts = [];
        foreach (self::KEY_FIELDS as $field) {
            $value = $violation[$field] ?? null;
            if (!is_string($value) || $value === '') {
                throw new InventoryError("a violation needs a non-empty \"{$field}\"");
            }
            $parts[] = $value;
        }

        return implode('|', $parts);
    }

    /**
     * @param  array<string, mixed> $raw
     * @return Entry
     */
    private function validate(array $raw, int $index): array
    {
        $where = "{$this->invariant} allowlist entry #{$index}";
        foreach (self::KEY_FIELDS as $field) {
            if (!is_string($raw[$field] ?? null) || $raw[$field] === '') {
                throw new InventoryError("{$where}: \"{$field}\" is required");
            }
        }
        $reason = $raw['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '') {
            throw new InventoryError("{$where} (".self::key($raw).'): "reason" is required');
        }
        $removedIn = $raw['removed_in'] ?? null;
        if (!is_string($removedIn) || !in_array($removedIn, self::REMOVED_IN, true)) {
            throw new InventoryError("{$where} (".self::key($raw).'): "removed_in" must be one of '.implode(', ', self::REMOVED_IN));
        }
        $category = $raw['category'] ?? null;
        if ($removedIn === 'never' && ($this->invariant !== 'I4' || !in_array($category, self::PERMANENT_CATEGORIES, true))) {
            throw new InventoryError("{$where} (".self::key($raw).'): "never" is only for I4 categories '.implode(', ', self::PERMANENT_CATEGORIES));
        }
        if ($this->invariant === 'I4' && !in_array($category, self::I4_CATEGORIES, true)) {
            throw new InventoryError("{$where} (".self::key($raw).'): I4 needs a "category" of '.implode(', ', self::I4_CATEGORIES));
        }
        $blocksFrom = self::BLOCKS_FROM[$this->invariant] ?? null;
        if ($blocksFrom !== null && $removedIn !== 'never' && $removedIn > $blocksFrom && !in_array($category, self::LATE_CATEGORIES, true)) {
            throw new InventoryError("{$where} (".self::key($raw)."): \"removed_in\" {$removedIn} is later than {$this->invariant} blocks ({$blocksFrom})");
        }
        $count = $raw['count'] ?? 1;
        if (!is_int($count) || $count < 1) {
            throw new InventoryError("{$where} (".self::key($raw).'): "count" must be a positive integer');
        }

        $entry = [
            'view' => (string) $raw['view'],
            'file' => (string) $raw['file'],
            'symbol' => (string) $raw['symbol'],
            'kind' => (string) $raw['kind'],
            'target' => (string) $raw['target'],
            'count' => $count,
            'reason' => $reason,
            'removed_in' => $removedIn,
        ];
        if (is_string($category) && $category !== '') {
            $entry['category'] = $category;
        }

        return $entry;
    }
}
