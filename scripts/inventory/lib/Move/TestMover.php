<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Move;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use Vaulter\Inventory\Repo;

/**
 * The tests side of a move: whole test directories change package, the namespaces of the test classes follow
 * (`Vaulter\Tests\` => `Vaulter\<Package>\Tests\`), tests/suites.json lists every test file in the partition of the
 * same TestCase key of the new package's suite, tests/zones.json forgets the old directory, and the baseline ledger
 * gets one entry per moved Pest case so that `scripts/t baseline --compare` finds it in the new suite.
 *
 * @phpstan-type TestMove array{from: string, to: string, files: list<array{from: string, to: string}>}
 * @phpstan-type LedgerEntry array{suite: string, old: string, new: string, new_suite?: string, reason: string, decision: string}
 */
final class TestMover
{
    public const SUITES = 'tests/suites.json';

    public const ZONES = 'tests/zones.json';

    public const LEDGER = 'tests/baseline/CHANGES.json';

    public const ORIGINAL = 'tests/baseline/original/%s.list';

    /**
     * Pairs `old:new` => the moved directories (or single files) with their files. A directory already moved (old gone, new present) is done.
     *
     * @param  list<string>   $pairs
     * @return list<TestMove>
     */
    public static function plan(Repo $repo, array $pairs): array
    {
        $moves = [];
        foreach ($pairs as $pair) {
            if (substr_count($pair, ':') !== 1) {
                throw new MoveUsage("--tests expects <old-dir>:<new-dir>, got [{$pair}]");
            }
            [$from, $to] = array_map(static fn (string $p): string => trim($p, '/'), explode(':', $pair));
            if ($from === '' || $to === '' || $from === $to) {
                throw new MoveUsage("--tests [{$pair}]: both directories are needed and must differ");
            }
            // A single test file of a mixed directory: `old/File.php:new/File.php`.
            $single = is_file($repo->path($from)) || (!is_dir($repo->path($from)) && str_ends_with($from, '.php'));
            if (!is_dir($repo->path($from)) && !is_file($repo->path($from))) {
                if (is_dir($repo->path($to)) || is_file($repo->path($to))) {
                    continue;
                }
                throw new MoveUsage("--tests [{$pair}]: neither path exists");
            }
            $files = [];
            foreach ($single ? [$from] : self::files($repo, $from) as $file) {
                $target = $single ? $to : $to.substr($file, strlen($from));
                if ($repo->exists($target)) {
                    throw new MoveUsage("--tests [{$pair}]: {$target} already exists");
                }
                $files[] = ['from' => $file, 'to' => $target];
            }
            $moves[] = ['from' => $from, 'to' => $to, 'files' => $files];
        }

        return $moves;
    }

    /**
     * The renamed test classes: old FQCN => new FQCN, and the namespace prefix rewrite of each moved file.
     *
     * @param  list<TestMove>                                                                     $moves
     * @return array{map: array<string, string>, namespaces: array<string, array{string,string}>}
     */
    public static function namespaces(Repo $repo, array $moves): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $map = [];
        $namespaces = [];
        foreach ($moves as $move) {
            $package = self::packageOf($move['to']);
            if ($package === null || $package === 'core') {
                continue;
            }
            // A test directory of core uses `Vaulter\Tests\`; one of another package its own `Vaulter\<Package>\Tests\`.
            $source = self::packageOf($move['from']);
            $from = $source === null || $source === 'core' ? 'Vaulter\\Tests\\' : 'Vaulter\\'.ucfirst($source).'\\Tests\\';
            $to = 'Vaulter\\'.ucfirst($package).'\\Tests\\';
            foreach ($move['files'] as $file) {
                if (!str_ends_with($file['from'], '.php')) {
                    continue;
                }
                $namespaces[$file['from']] = [rtrim($from, '\\'), rtrim($to, '\\')];
                try {
                    $ast = $parser->parse($repo->read($file['from'])) ?? [];
                } catch (Throwable $e) {
                    throw new MoveFailure("{$file['from']}: {$e->getMessage()}", 0, $e);
                }
                $visitor = new class extends NodeVisitorAbstract
                {
                    /** @var list<string> */
                    public array $types = [];

                    private string $namespace = '';

                    public function enterNode(Node $node): null
                    {
                        if ($node instanceof Stmt\Namespace_) {
                            $this->namespace = $node->name?->toString() ?? '';
                        } elseif ($node instanceof Stmt\ClassLike && $node->name !== null) {
                            $this->types[] = ($this->namespace === '' ? '' : $this->namespace.'\\').$node->name->toString();
                        }

                        return null;
                    }
                };
                (new NodeTraverser($visitor))->traverse($ast);
                foreach ($visitor->types as $fqcn) {
                    if (str_starts_with($fqcn, $from)) {
                        $map[$fqcn] = $to.substr($fqcn, strlen($from));
                    }
                }
            }
        }

        return ['map' => $map, 'namespaces' => $namespaces];
    }

    /**
     * Moves the test files in tests/suites.json; returns the new document, the suites each file left/entered and warnings.
     *
     * @param  list<TestMove>                                                                                                                                     $moves
     * @return array{suites: array<string, mixed>, files: array<string, array{from: string, to: string, partition: string, new: string}>, warnings: list<string>}
     */
    public static function suites(Repo $repo, array $moves): array
    {
        /** @var array<string, mixed> $document */
        $document = $repo->readJson(self::SUITES);
        $files = [];
        $warnings = [];
        $located = [];
        foreach ($document['suites'] as $suite => $definition) {
            foreach ($definition['partitions'] ?? [] as $index => $partition) {
                foreach ($partition['files'] as $file) {
                    $located[$file] = [(string) $suite, (int) $index];
                }
            }
        }
        foreach ($moves as $move) {
            foreach ($move['files'] as $file) {
                if (!isset($located[$file['from']])) {
                    continue;
                }
                [$from, $index] = $located[$file['from']];
                $key = (string) $document['suites'][$from]['partitions'][$index]['key'];
                $to = self::suiteOf($document, $file['to']);
                $target = null;
                foreach ($document['suites'][$to]['partitions'] ?? [] as $i => $partition) {
                    if ($partition['key'] === $key) {
                        $target = $i;
                    }
                }
                if ($target === null) {
                    throw new MoveUsage("{$file['to']}: the suite [{$to}] has no partition [{$key}]; add the partition (same TestCase key) to tests/suites.json first");
                }
                $files[$file['from']] = ['from' => $from, 'to' => $to, 'partition' => $key, 'new' => $file['to'], 'target' => $target, 'index' => $index];
            }
        }
        foreach ($files as $oldPath => $info) {
            $list = &$document['suites'][$info['from']]['partitions'][$info['index']]['files'];
            $list = array_values(array_filter($list, static fn (string $f): bool => $f !== $oldPath));
            unset($list);
        }
        foreach ($files as $info) {
            $list = &$document['suites'][$info['to']]['partitions'][$info['target']]['files'];
            $list[] = $info['new'];
            sort($list, SORT_STRING);
            unset($list);
        }
        foreach ($document['suites'] as $suite => $definition) {
            $kept = [];
            foreach ($definition['partitions'] ?? [] as $partition) {
                if ($partition['files'] === [] && self::emptied($files, (string) $suite, (string) $partition['key'])) {
                    $warnings[] = "tests/suites.json: the partition [{$partition['key']}] of [{$suite}] is empty and removed";

                    continue;
                }
                $kept[] = $partition;
            }
            if (isset($definition['partitions'])) {
                $document['suites'][$suite]['partitions'] = $kept;
            }
        }

        return [
            'suites' => $document,
            'files' => array_map(static fn (array $i): array => ['from' => $i['from'], 'to' => $i['to'], 'partition' => $i['partition'], 'new' => $i['new']], $files),
            'warnings' => $warnings,
        ];
    }

    /**
     * The ledger entries of the moved Pest cases: every case of an original list whose class is a moved file.
     *
     * @param  array<string, mixed>                                                           $suites the document of tests/suites.json
     * @param  array<string, array{from: string, to: string, partition: string, new: string}> $files  by old path
     * @return list<LedgerEntry>
     */
    public static function ledger(Repo $repo, array $suites, array $files, string $decision, string $phase = 'P4'): array
    {
        $entries = [];
        $originals = [];
        foreach ($files as $oldPath => $info) {
            $from = $suites['suites'][$info['from']];
            $original = $originals[$info['from']] ??= self::original($repo, $info['from']);
            if ($original === null) {
                continue;
            }
            $class = self::pestClass($from, $oldPath);
            $newPath = $info['new'];
            $newClass = self::pestClass($suites['suites'][$info['to']], $newPath);
            foreach ($original as $name) {
                if (!str_starts_with($name, $class.'::')) {
                    continue;
                }
                $entry = [
                    'suite' => $info['from'],
                    'old' => $name,
                    'new' => $newClass.substr($name, strlen($class)),
                ];
                if ($info['to'] !== $info['from']) {
                    $entry['new_suite'] = $info['to'];
                }
                $entries[] = $entry + ['reason' => "the test file moved to {$newPath} ({$phase} ownership move)", 'decision' => $decision];
            }
        }

        return $entries;
    }

    /** The Pest class name of a test file: `P\` and the path from the suite base, the first segment capitalised. */
    public static function pestClass(array $suite, string $file): string
    {
        $relative = substr($file, 0, -strlen('.php'));
        if (($suite['path_base'] ?? 'repo') === 'package') {
            $relative = substr($relative, strlen(rtrim((string) $suite['cwd'], '/')) + 1);
        }
        $segments = explode('/', $relative);
        $segments[0] = ucfirst($segments[0]);

        return 'P\\'.implode('\\', $segments);
    }

    /**
     * Removes the old directories from tests/zones.json and lists a new one the zones do not cover (text edit: the
     * file is hand-formatted and is not re-encoded).
     *
     * @param  list<TestMove>                              $moves
     * @return array{text: string, warnings: list<string>}
     */
    public static function zones(Repo $repo, array $moves): array
    {
        $text = $repo->read(self::ZONES);
        $warnings = [];
        /** @var array{zones: array<string, array<string, mixed>>} $document */
        $document = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        $entries = [];
        foreach ($document['zones'] as $zone) {
            foreach (['src', 'tests'] as $kind) {
                foreach ($zone[$kind] ?? [] as $path) {
                    $entries[] = $path;
                }
            }
        }
        $lines = explode("\n", $text);
        foreach ($moves as $move) {
            $lines = array_values(array_filter($lines, static function (string $line) use ($move): bool {
                if (preg_match('/^\s*"([^"]+)",?\s*$/', $line, $m) !== 1) {
                    return true;
                }

                return $m[1] !== $move['from'] && !str_starts_with($m[1], $move['from'].'/');
            }));
        }
        foreach ($lines as $i => $line) {
            $next = $lines[$i + 1] ?? '';
            if (str_ends_with(rtrim($line), ',') && preg_match('/^\s*[\]}]/', $next) === 1) {
                $lines[$i] = rtrim(substr(rtrim($line), 0, -1));
            }
        }
        $text = implode("\n", $lines);

        foreach ($moves as $move) {
            $covered = false;
            foreach ($entries as $path) {
                if ($move['to'] === $path || str_starts_with($move['to'], $path.'/')) {
                    $covered = true;
                }
            }
            if ($covered) {
                continue;
            }
            $package = self::packageOf($move['to']);
            if ($package === null || !isset($document['zones'][$package]['tests'])) {
                throw new MoveUsage("tests/zones.json: no zone covers {$move['to']} and no zone [".($package ?? '?').'] lists tests; add one first');
            }
            $text = self::appendTest($text, $package, $move['to']);
        }

        return ['text' => $text, 'warnings' => $warnings];
    }

    /** The package of a path `packages/<p>/…`. */
    public static function packageOf(string $path): ?string
    {
        return preg_match('#^packages/([^/]+)/#', $path, $m) === 1 ? $m[1] : null;
    }

    /** @return list<string> */
    private static function files(Repo $repo, string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo->path($directory), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($repo->root()) + 1);
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /** @param array<string, mixed> $document */
    private static function suiteOf(array $document, string $file): string
    {
        $best = null;
        foreach ($document['suites'] as $name => $definition) {
            $dir = rtrim((string) ($definition['tests_dir'] ?? ''), '/');
            if ($dir !== '' && str_starts_with($file, $dir.'/') && ($best === null || strlen($dir) > strlen((string) $document['suites'][$best]['tests_dir']))) {
                $best = (string) $name;
            }
        }

        return $best ?? throw new MoveUsage("{$file} is under no suite's tests_dir in tests/suites.json");
    }

    /** @param array<string, array<string, mixed>> $files */
    private static function emptied(array $files, string $suite, string $key): bool
    {
        foreach ($files as $info) {
            if ($info['from'] === $suite && $info['partition'] === $key) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string>|null */
    private static function original(Repo $repo, string $suite): ?array
    {
        $path = sprintf(self::ORIGINAL, $suite);
        if (!$repo->exists($path)) {
            return null;
        }

        return array_values(array_filter(explode("\n", $repo->read($path)), static fn (string $l): bool => $l !== ''));
    }

    private static function appendTest(string $text, string $zone, string $path): string
    {
        $at = strpos($text, '"'.$zone.'": {');
        $open = $at === false ? false : strpos($text, '"tests": [', $at);
        $close = $open === false ? false : strpos($text, ']', $open);
        if ($open === false || $close === false) {
            throw new MoveUsage("tests/zones.json: cannot extend the tests of the zone [{$zone}]");
        }
        $body = rtrim(substr($text, $open, $close - $open));
        if (str_ends_with($body, '[')) {
            throw new MoveUsage("tests/zones.json: the tests of the zone [{$zone}] are empty or inline; extend them by hand");
        }
        $indent = preg_match('/\n(\s*)"[^"]+"$/', $body, $m) === 1 ? $m[1] : '        ';
        $closing = preg_match('/\n(\s*)$/', substr($text, $open, $close - $open), $c) === 1 ? $c[1] : '    ';

        return substr($text, 0, $open).$body.",\n{$indent}\"{$path}\"\n{$closing}".substr($text, $close);
    }
}
