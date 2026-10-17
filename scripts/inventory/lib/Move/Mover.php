<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Move;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Vaulter\Inventory\Repo;

/**
 * Moves the selected types of the target-map between packages (p4-design#move-tool). Everything is computed in memory
 * first (selection, conflicts, new file contents, suites, zones, ledger); only then are the files written, so a
 * conflict or an unparsable file leaves the tree untouched. Running it again changes nothing.
 *
 * @phpstan-import-type Move from Plan
 * @phpstan-import-type Options from Plan
 * @phpstan-import-type TestMove from TestMover
 * @phpstan-import-type LedgerEntry from TestMover
 *
 * @phpstan-type Report array{moved: list<array{old: string, new: string, file_from: string, file_to: string}>, edits: array<string, int>, strings: array<string, int>, tests: list<array{from: string, to: string, files: list<array{from: string, to: string, suite_from: ?string, suite_to: ?string, partition: ?string}>}>, ledger: list<LedgerEntry>, warnings: list<string>}
 */
final class Mover
{
    /** Directories never read or rewritten (generated fixtures, immutable baselines, plans, vendor code). */
    private const SKIP_PATHS = [
        'plans', 'tests/baseline', 'tests/Repo/Arch/Fixtures', 'tests/Repo/Fixtures', 'packages/core/tests/Fixtures/Schema/original',
        '.swissknifeman', '.claude', '.agents', '.idea', 'docs/.vitepress/cache',
    ];

    private const SKIP_NAMES = ['vendor', 'node_modules', '.git', 'dist'];

    public function __construct(private readonly Repo $repo, private readonly bool $git = true) {}

    /**
     * @param  Options      $options
     * @param  list<string> $tests   `old-dir:new-dir` pairs
     * @return Report
     */
    public function run(array $options, array $tests, string $decision, bool $apply): array
    {
        // Test-only runs (`--tests` after the types moved earlier) have nothing to select.
        $moves = Plan::select($this->repo, $options + ['allow_empty' => $tests !== []]);
        $testMoves = TestMover::plan($this->repo, $tests);
        $testNames = TestMover::namespaces($this->repo, $testMoves);

        $map = $testNames['map'];
        $fileMoves = [];
        foreach ($moves as $move) {
            $map[$move['old']] = $move['new'];
            if (!$move['done']) {
                $fileMoves[$move['file_from']] = $move['file_to'];
            }
        }
        foreach ($testMoves as $testMove) {
            foreach ($testMove['files'] as $file) {
                $fileMoves[$file['from']] = $file['to'];
            }
        }

        $owner = ucfirst($options['owner']);
        $moved = array_flip(array_values($map));
        $text = new FqcnText($map);
        $rewriter = new PhpRewriter($map, $text, $testNames['namespaces'], Plan::known($this->repo), static fn (string $fqcn): string => isset($moved[$fqcn]) ? $owner : 'Core');
        $shorts = $this->shortNames(array_keys($map));

        $contents = [];
        $edits = [];
        $strings = [];
        $warnings = [];
        foreach ($this->scope($fileMoves) as [$path, $kind]) {
            $source = $this->repo->read($path);
            $isMoved = isset($fileMoves[$path]);
            $count = 0;
            if ($kind === 'php' && ($isMoved || preg_match($shorts, $source) === 1)) {
                $result = $rewriter->rewrite($path, $source);
                $code = $result['code'];
                array_push($warnings, ...$result['warnings']);
                $count = $result['edits'] + $result['strings'];
                $final = $fileMoves[$path] ?? $path;
                if ($result['edits'] > 0) {
                    $edits[$final] = $result['edits'];
                }
                if ($result['strings'] > 0) {
                    $strings[$final] = $result['strings'];
                }
            } elseif ($kind === 'php') {
                $code = $source;
            } elseif (str_contains($source, "\0")) {
                $code = $source;
            } else {
                $code = $text->replace($source, $count);
                if ($count > 0) {
                    $strings[$fileMoves[$path] ?? $path] = $count;
                }
            }
            if ($code !== $source) {
                $contents[$path] = $code;
            }
        }

        $suites = null;
        $zones = null;
        $ledger = [];
        $testReport = [];
        if ($testMoves !== []) {
            $suites = TestMover::suites($this->repo, $testMoves);
            $zones = TestMover::zones($this->repo, $testMoves);
            array_push($warnings, ...$suites['warnings'], ...$zones['warnings']);
            $ledger = $this->newEntries(TestMover::ledger($this->repo, $suites['suites'], $suites['files'], $decision));
            foreach ($testMoves as $testMove) {
                $testReport[] = [
                    'from' => $testMove['from'],
                    'to' => $testMove['to'],
                    'files' => array_map(static fn (array $f): array => [
                        'from' => $f['from'],
                        'to' => $f['to'],
                        'suite_from' => $suites['files'][$f['from']]['from'] ?? null,
                        'suite_to' => $suites['files'][$f['from']]['to'] ?? null,
                        'partition' => $suites['files'][$f['from']]['partition'] ?? null,
                    ], $testMove['files']),
                ];
            }
        }

        $report = [
            'moved' => array_values(array_map(
                static fn (array $m): array => ['old' => $m['old'], 'new' => $m['new'], 'file_from' => $m['file_from'], 'file_to' => $m['file_to']],
                array_filter($moves, static fn (array $m): bool => !$m['done']),
            )),
            'edits' => $edits,
            'strings' => $strings,
            'tests' => $testReport,
            'ledger' => $ledger,
            'warnings' => array_values(array_unique($warnings)),
        ];

        if ($apply) {
            $this->write($fileMoves, $contents);
            if ($suites !== null && $zones !== null) {
                $this->repo->write(TestMover::SUITES, self::encode($suites['suites']));
                $this->repo->write(TestMover::ZONES, $zones['text']);
                $this->appendLedger($ledger);
            }
        }

        return $report;
    }

    /**
     * @param array<string, string> $fileMoves old path => new path
     * @param array<string, string> $contents  old path => new contents
     */
    private function write(array $fileMoves, array $contents): void
    {
        ksort($fileMoves, SORT_STRING);
        foreach ($fileMoves as $from => $to) {
            $directory = dirname($this->repo->path($to));
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new MoveFailure("cannot create {$directory}");
            }
            if ($this->git) {
                $this->git(['mv', '--', $from, $to]);
            } elseif (!rename($this->repo->path($from), $this->repo->path($to))) {
                throw new MoveFailure("cannot move {$from} to {$to}");
            }
            $this->prune(dirname($from));
        }
        foreach ($contents as $path => $code) {
            $this->repo->write($fileMoves[$path] ?? $path, $code);
        }
    }

    /** Removes the directories a move emptied, up to (not including) the package directory. */
    private function prune(string $directory): void
    {
        while (substr_count($directory, '/') >= 2 && is_dir($this->repo->path($directory)) && (new FilesystemIterator($this->repo->path($directory)))->valid() === false) {
            rmdir($this->repo->path($directory));
            $directory = dirname($directory);
        }
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): void
    {
        $process = proc_open(['git', '-C', $this->repo->root(), ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new MoveFailure('cannot start git');
        }
        stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new MoveFailure('git '.implode(' ', $arguments).': '.trim($error).' (an untracked file? use --no-git)');
        }
    }

    /**
     * @param  list<LedgerEntry> $entries
     * @return list<LedgerEntry>
     */
    private function newEntries(array $entries): array
    {
        if (!$this->repo->exists(TestMover::LEDGER)) {
            return $entries;
        }
        /** @var list<array<string, mixed>> $present */
        $present = $this->repo->readJson(TestMover::LEDGER)['entries'] ?? [];
        $known = [];
        foreach ($present as $entry) {
            $known[$entry['suite'].'|'.$entry['old']] = true;
        }

        return array_values(array_filter($entries, static fn (array $e): bool => !isset($known[$e['suite'].'|'.$e['old']])));
    }

    /** @param list<LedgerEntry> $entries */
    private function appendLedger(array $entries): void
    {
        if ($entries === []) {
            return;
        }
        $document = $this->repo->exists(TestMover::LEDGER) ? $this->repo->readJson(TestMover::LEDGER) : ['schema' => 1, 'entries' => [], 'additions' => []];
        $document['entries'] = [...($document['entries'] ?? []), ...$entries];
        if (($document['additions'] ?? []) === []) {
            $document['additions'] = new \stdClass;
        }
        $this->repo->write(TestMover::LEDGER, self::encode($document));
    }

    /** The JSON of tests/suites.json and the ledger: two-space indent, as `jq .` writes it. */
    public static function encode(mixed $document): string
    {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json)."\n";
    }

    /** @param list<string> $olds */
    private function shortNames(array $olds): string
    {
        $shorts = array_unique(array_map(static fn (string $f): string => substr($f, (int) strrpos($f, '\\') + 1), $olds));

        return '/\b(?:'.implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), $shorts)).')\b/';
    }

    /**
     * Every file the move may touch, as [repo-relative path, php|text], sorted.
     *
     * @param  array<string, string>       $fileMoves
     * @return list<array{string, string}>
     */
    private function scope(array $fileMoves): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($this->repo->root(), FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $file, string $key, RecursiveDirectoryIterator $it): bool {
                $relative = substr($file->getPathname(), strlen($this->repo->root()) + 1);
                if ($file->isDir()) {
                    return !in_array($file->getFilename(), self::SKIP_NAMES, true) && !in_array($relative, self::SKIP_PATHS, true);
                }

                return true;
            },
        ));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($this->repo->root()) + 1);
            $kind = self::kind($relative);
            if ($kind !== null) {
                $files[$relative] = $kind;
            }
        }
        foreach (array_keys($fileMoves) as $path) {
            if (!isset($files[$path]) && $this->repo->exists($path) && !str_starts_with($path, 'tests/baseline/')) {
                $files[$path] = str_ends_with($path, '.php') ? 'php' : 'text';
            }
        }
        ksort($files, SORT_STRING);

        $list = [];
        foreach ($files as $path => $kind) {
            $list[] = [$path, $kind];
        }

        return $list;
    }

    /** php for the code roots, text for docs and configuration, null for everything else. */
    private static function kind(string $path): ?string
    {
        if (basename($path) === 'CHANGELOG.md' || basename($path) === 'package-lock.json') {
            return null;
        }
        if (str_ends_with($path, '.php')) {
            return preg_match('#^(packages/[^/]+/(src|tests|database|config|routes)|load/(app|tests)|scripts/inventory|e2e)/#', $path) === 1 ? 'php' : null;
        }
        if (str_starts_with($path, 'openapi/')) {
            return 'text';
        }
        $text = match (true) {
            str_ends_with($path, '.md') => str_starts_with($path, 'docs/') || $path === 'README.md' || preg_match('#^packages/[^/]+/README\.md$#', $path) === 1,
            str_ends_with($path, '.neon'), str_ends_with($path, '.json') => true,
            str_ends_with($path, '.yml'), str_ends_with($path, '.yaml') => str_starts_with($path, '.github/'),
            default => preg_match('#^(packages/[^/]+/)?phpunit[^/]*\.xml(\.dist)?$#', $path) === 1,
        };

        return $text ? 'text' : null;
    }
}
