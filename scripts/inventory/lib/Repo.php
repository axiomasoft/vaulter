<?php

declare(strict_types=1);

namespace Vaulter\Inventory;

use JsonException;
use RuntimeException;

/** A rule or fixture error: the inventory cannot judge anything (CLI exit 2). */
final class InventoryError extends RuntimeException {}

/** @phpstan-type Outcome array{code: int, lines: list<string>} */
interface Generator
{
    /** kebab-case id, equal to the basename of generators/<name>.php. */
    public function name(): string;

    /**
     * @param  array<string, string> $options
     * @return list<string>          the files written, repo-relative
     */
    public function write(Repo $repo, array $options = []): array;

    /**
     * Recomputes in memory and compares byte-for-byte with the committed fixture: 0 equal, 1 different, 2 rule error.
     *
     * @param  array<string, string> $options
     * @return Outcome
     */
    public function check(Repo $repo, array $options = []): array;

    /**
     * Like check(), plus a summary of what was found.
     *
     * @param  array<string, string> $options
     * @return Outcome
     */
    public function report(Repo $repo, array $options = []): array;
}

/** The repository root, its fixtures and the packages fixture. */
final class Repo
{
    public const PACKAGES_FIXTURE = 'tests/Repo/Arch/Fixtures/packages.json';

    private function __construct(private readonly string $root) {}

    public static function discover(): self
    {
        return new self(dirname(__DIR__, 3));
    }

    public static function at(string $root): self
    {
        return new self(rtrim($root, '/'));
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relative): string
    {
        return $this->root.'/'.ltrim($relative, '/');
    }

    public function exists(string $relative): bool
    {
        return file_exists($this->path($relative));
    }

    public function read(string $relative): string
    {
        $contents = @file_get_contents($this->path($relative));
        if ($contents === false) {
            throw new InventoryError("cannot read {$relative}");
        }

        return $contents;
    }

    /** @return array<string, mixed> */
    public function readJson(string $relative): array
    {
        return Json::decode($this->read($relative), $relative);
    }

    public function write(string $relative, string $contents): void
    {
        $path = $this->path($relative);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new InventoryError("cannot create the directory of {$relative}");
        }
        file_put_contents($path, $contents);
    }

    /**
     * The packages fixture: name => {dir?, exists, composer?, psr4, current_require?, target_require}.
     *
     * @return array<string, array<string, mixed>>
     */
    public function packages(): array
    {
        $packages = $this->readJson(self::PACKAGES_FIXTURE)['packages'] ?? null;
        if (!is_array($packages)) {
            throw new InventoryError(self::PACKAGES_FIXTURE.' has no "packages" object');
        }

        /** @var array<string, array<string, mixed>> $packages */
        return $packages;
    }

    /**
     * PSR-4 prefix => package for every package of the fixture, the not yet existing ones included (their target).
     *
     * @return array<string, string>
     */
    public function psr4(): array
    {
        $map = [];
        foreach ($this->packages() as $name => $package) {
            $psr4 = $package['psr4'] ?? [];
            if (is_array($psr4)) {
                foreach (array_keys($psr4) as $prefix) {
                    $map[(string) $prefix] = $name;
                }
            }
        }

        return $map;
    }
}

/** Canonical JSON: sorted object keys, 4-space pretty print, a trailing newline; lists keep their order. */
final class Json
{
    public static function encode(mixed $data): string
    {
        return json_encode(self::canonical($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    public static function write(Repo $repo, string $relative, mixed $data): void
    {
        $repo->write($relative, self::encode($data));
    }

    /** @return array<string, mixed> */
    public static function decode(string $json, string $what = 'json'): array
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InventoryError("{$what}: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data)) {
            throw new InventoryError("{$what}: an object is expected");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    private static function canonical(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        $sorted = array_map(self::canonical(...), $data);
        if (!array_is_list($sorted)) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }
}

/** A short unified diff of the first region where two texts differ. */
final class Diff
{
    private const CONTEXT = 3;

    private const LIMIT = 60;

    /** @return list<string> empty when the texts are equal */
    public static function unified(string $expected, string $actual, string $label): array
    {
        if ($expected === $actual) {
            return [];
        }
        $a = explode("\n", $expected);
        $b = explode("\n", $actual);

        $head = 0;
        $max = min(count($a), count($b));
        while ($head < $max && $a[$head] === $b[$head]) {
            $head++;
        }
        $tail = 0;
        while ($tail < $max - $head && $a[count($a) - 1 - $tail] === $b[count($b) - 1 - $tail]) {
            $tail++;
        }

        $from = max(0, $head - self::CONTEXT);
        $removed = array_slice($a, $head, count($a) - $head - $tail);
        $added = array_slice($b, $head, count($b) - $head - $tail);

        $body = [];
        foreach (array_slice($a, $from, $head - $from) as $context) {
            $body[] = ' '.$context;
        }
        array_push($body, ...self::region($removed, $added));
        foreach (array_slice($a, count($a) - $tail, min($tail, self::CONTEXT)) as $context) {
            $body[] = ' '.$context;
        }

        $before = count(array_filter($body, static fn (string $l): bool => $l[0] !== '+'));
        $after = count(array_filter($body, static fn (string $l): bool => $l[0] !== '-'));
        $lines = ["--- {$label} (committed)", "+++ {$label} (generated)", sprintf('@@ -%d,%d +%d,%d @@', $from + 1, $before, $from + 1, $after)];
        array_push($lines, ...array_slice($body, 0, self::LIMIT));
        if (count($body) > self::LIMIT) {
            $lines[] = sprintf('… %d more line(s)', count($body) - self::LIMIT);
        }

        return $lines;
    }

    /**
     * Longest-common-subsequence diff of the changed region; a block replace when it is too large to align.
     *
     * @param  list<string> $removed
     * @param  list<string> $added
     * @return list<string>
     */
    private static function region(array $removed, array $added): array
    {
        $n = count($removed);
        $m = count($added);
        if ($n * $m > 4_000_000) {
            return [...array_map(static fn (string $l): string => '-'.$l, $removed), ...array_map(static fn (string $l): string => '+'.$l, $added)];
        }

        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $removed[$i] === $added[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $out = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($removed[$i] === $added[$j]) {
                $out[] = ' '.$removed[$i];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = '-'.$removed[$i++];
            } else {
                $out[] = '+'.$added[$j++];
            }
        }
        while ($i < $n) {
            $out[] = '-'.$removed[$i++];
        }
        while ($j < $m) {
            $out[] = '+'.$added[$j++];
        }

        return $out;
    }
}
