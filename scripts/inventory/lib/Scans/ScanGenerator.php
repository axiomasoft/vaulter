<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Scans;

use Closure;
use Vaulter\Inventory\Allowlist;
use Vaulter\Inventory\Generator;
use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Json;
use Vaulter\Inventory\Repo;

require_once __DIR__.'/Scan.php';
require_once __DIR__.'/ScanContext.php';

/**
 * scan-i<N>: one boundary scan against its hand-written allowlist (tests/Repo/Arch/Fixtures/allowlists/i<N>.json).
 * There is nothing to generate: `--write` writes no file, `--check` is red on a new or a stale entry, `--report`
 * prints what was found (and, with `--proposals=<path>`, the entries to fill in with reason/category/removed_in).
 *
 * @phpstan-import-type Violation from Scan
 */
final class ScanGenerator implements Generator
{
    public const DIR = 'tests/Repo/Arch/Fixtures/allowlists';

    /** A reason that stands for "not decided yet" is as good as none. */
    private const PLACEHOLDERS = ['todo', 'tbd', 'fixme', 'xxx', '?', '??', '-', 'n/a'];

    /** @param Closure(Repo): list<Violation> $found */
    public function __construct(private readonly string $invariant, private readonly Closure $found) {}

    public static function allowlistPath(string $invariant): string
    {
        return self::DIR.'/'.strtolower($invariant).'.json';
    }

    public function name(): string
    {
        return 'scan-'.strtolower($this->invariant);
    }

    public function write(Repo $repo, array $options = []): array
    {
        return [];
    }

    public function check(Repo $repo, array $options = []): array
    {
        return $this->judge($repo, $options, false);
    }

    public function report(Repo $repo, array $options = []): array
    {
        return $this->judge($repo, $options, true);
    }

    /**
     * The verdict of one scan: exit 1 when something is new (found, not allowed) or stale (allowed, gone or recounted).
     *
     * @param  list<Violation>                       $found
     * @return array{code: int, lines: list<string>}
     */
    public static function evaluate(string $invariant, array $found, Allowlist $allowlist): array
    {
        self::assertReasons($allowlist);
        $lines = Allowlist::describe($allowlist->compare($found));
        if ($lines === []) {
            return ['code' => 0, 'lines' => []];
        }
        $lines[] = sprintf('fix: scripts/php84 php scripts/inventory/inventory.php %s --report [--proposals=<path>]; a new entry needs reason, category and removed_in, a stale one is removed or recounted in %s', 'scan-'.strtolower($invariant), self::allowlistPath($invariant));

        return ['code' => 1, 'lines' => $lines];
    }

    /** @throws InventoryError */
    public static function assertReasons(Allowlist $allowlist): void
    {
        foreach ($allowlist->entries() as $entry) {
            if (in_array(strtolower(trim($entry['reason'])), self::PLACEHOLDERS, true)) {
                throw new InventoryError("{$allowlist->invariant} allowlist: placeholder reason \"{$entry['reason']}\" for ".Allowlist::key($entry));
            }
        }
    }

    /**
     * @param  array<string, string>                 $options
     * @return array{code: int, lines: list<string>}
     */
    private function judge(Repo $repo, array $options, bool $report): array
    {
        $path = self::allowlistPath($this->invariant);
        $found = ($this->found)($repo);

        if (!$repo->exists($path)) {
            if (!$report) {
                throw new InventoryError("{$path} is missing; seed it from scripts/php84 php scripts/inventory/inventory.php {$this->name()} --report --proposals=<path>");
            }
            $allowlist = Allowlist::fromArray(['invariant' => $this->invariant, 'mode' => 'report', 'entries' => []], $path);
        } else {
            $allowlist = Allowlist::load($repo, $path);
        }
        if ($allowlist->invariant !== $this->invariant) {
            throw new InventoryError("{$path}: invariant {$allowlist->invariant}, expected {$this->invariant}");
        }

        $outcome = self::evaluate($this->invariant, $found, $allowlist);
        if (!$report) {
            return $outcome;
        }

        $lines = [sprintf('%d violations at %d places', array_sum(array_column($found, 'count')), count($found)), ...self::summary($found), ...$outcome['lines']];
        if (($options['proposals'] ?? '') !== '') {
            $lines[] = 'proposals written to '.$this->proposals($repo, $options['proposals'], $found);
        }

        return ['code' => $outcome['code'], 'lines' => $lines];
    }

    /**
     * @param  list<Violation> $found
     * @return list<string>
     */
    private static function summary(array $found): array
    {
        $counts = [];
        foreach ($found as $violation) {
            $key = sprintf('%s %s -> %s%s', $violation['view'], $violation['kind'], $violation['target_owner'], isset($violation['category']) ? " [{$violation['category']}]" : '');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return array_map(static fn (string $key, int $n): string => sprintf('%5d  %s', $n, $key), array_keys($counts), $counts);
    }

    /** @param list<Violation> $found */
    private function proposals(Repo $repo, string $path, array $found): string
    {
        $entries = [];
        foreach ($found as $violation) {
            $entry = [
                'view' => $violation['view'],
                'file' => $violation['file'],
                'symbol' => $violation['symbol'],
                'kind' => $violation['kind'],
                'target' => $violation['target'],
                'count' => $violation['count'],
                'target_owner' => $violation['target_owner'],
            ];
            if (isset($violation['category'])) {
                $entry['category'] = $violation['category'];
            }
            $entries[] = $entry;
        }
        $contents = Json::encode(['invariant' => $this->invariant, 'mode' => 'report', 'entries' => $entries]);
        if (str_starts_with($path, '/')) {
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
                throw new InventoryError("cannot create the directory of {$path}");
            }
            file_put_contents($path, $contents);
        } else {
            $repo->write($path, $contents);
        }

        return $path;
    }
}
