<?php

declare(strict_types=1);

namespace Vaulter\Release\Contract;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The bytes of contract inputs, read as data only: from the worktree by validated relative path, or from a verified commit
 * with `git cat-file` / `git ls-tree` (argument arrays, no shell). Nothing is checked out, required, autoloaded or executed.
 * A path with `..`, an absolute path, an option-like path, a symlink (escape or not) is an error, so is an unverified SHA.
 */
final class SourceReader
{
    private const PATH = '#^[A-Za-z0-9_][A-Za-z0-9_.@+-]*(?:/[A-Za-z0-9_.@+-]+)*$#';

    private const GLOB = '#^[A-Za-z0-9_][A-Za-z0-9_.@+*?-]*(?:/[A-Za-z0-9_.@+*?-]+)*$#';

    private function __construct(private readonly string $root, private readonly ?string $revision) {}

    public static function worktree(string $root): self
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new ContractError("not a directory: {$root}");
        }

        return new self($real, null);
    }

    public static function revision(string $root, string $sha): self
    {
        if (preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/', $sha) !== 1) {
            throw new ContractError("not a full commit SHA: {$sha}");
        }
        $reader = self::worktree($root);
        [$code, $type] = $reader->git(['cat-file', '-t', $sha]);
        if ($code !== 0 || trim($type) !== 'commit') {
            throw new ContractError("not a commit of {$root}: {$sha}");
        }

        return new self($reader->root, $sha);
    }

    public function revisionSha(): ?string
    {
        return $this->revision;
    }

    public static function validPath(string $path, bool $glob = false): string
    {
        $segments = explode('/', $path);
        if (strlen($path) > 1024 || preg_match($glob ? self::GLOB : self::PATH, $path) !== 1 || in_array('..', $segments, true) || in_array('.', $segments, true)) {
            throw new ContractError("unsafe contract path: {$path}");
        }

        return $path;
    }

    /** The bytes of one file, or null when it does not exist. */
    public function read(string $path): ?string
    {
        self::validPath($path);
        if ($this->revision === null) {
            $full = $this->root.'/'.$path;
            if (!file_exists($full) && !is_link($full)) {
                return null;
            }
            if (realpath($full) !== $full || is_link($full) || !is_file($full)) {
                throw new ContractError("not a regular file inside the root (symlink or escape): {$path}");
            }

            return (string) file_get_contents($full);
        }

        [$code, $entry] = $this->git(['ls-tree', '-z', $this->revision, '--', $path]);
        if ($code !== 0) {
            throw new ContractError("git ls-tree failed for {$path}");
        }
        if ($entry === '') {
            return null;
        }
        if (preg_match('/^(\d{6}) (\w+) ([0-9a-f]+)\t/', $entry, $m) !== 1 || $m[2] !== 'blob' || $m[1] === '120000') {
            throw new ContractError("not a regular file at {$this->revision}: {$path}");
        }
        [$code, $bytes] = $this->git(['cat-file', 'blob', $m[3]]);
        if ($code !== 0) {
            throw new ContractError("git cat-file failed for {$path}");
        }

        return $bytes;
    }

    /**
     * The files a glob names (`*` and `?` within one segment), sorted.
     *
     * @return list<string>
     */
    public function glob(string $pattern): array
    {
        self::validPath($pattern, true);
        $fixed = [];
        foreach (explode('/', $pattern) as $segment) {
            if (strpbrk($segment, '*?') !== false) {
                break;
            }
            $fixed[] = $segment;
        }
        $prefix = implode('/', array_slice($fixed, 0, count($fixed) === count(explode('/', $pattern)) ? -1 : count($fixed)));

        $candidates = [];
        if ($this->revision === null) {
            $dir = $this->root.($prefix === '' ? '' : '/'.$prefix);
            if (is_dir($dir) && !is_link($dir)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file instanceof SplFileInfo && ($file->isFile() || $file->isLink())) {
                        $candidates[] = substr($file->getPathname(), strlen($this->root) + 1);
                    }
                }
            }
        } else {
            [$code, $list] = $this->git(['ls-tree', '-r', '-z', '--name-only', $this->revision, '--', $prefix === '' ? '.' : $prefix]);
            if ($code !== 0) {
                throw new ContractError("git ls-tree failed for {$pattern}");
            }
            $candidates = array_values(array_filter(explode("\0", $list), static fn (string $p): bool => $p !== ''));
        }
        $found = array_values(array_filter($candidates, static fn (string $p): bool => fnmatch($pattern, $p, FNM_PATHNAME | FNM_PERIOD)));
        sort($found, SORT_STRING);

        return $found;
    }

    /**
     * @param  list<string>           $arguments
     * @return array{0: int, 1: string}
     */
    private function git(array $arguments): array
    {
        $process = proc_open(['git', '-c', 'core.fsmonitor=false', '-C', $this->root, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new ContractError('cannot run git');
        }
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out];
    }
}
