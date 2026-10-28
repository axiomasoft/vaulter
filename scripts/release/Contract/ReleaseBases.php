<?php

declare(strict_types=1);

namespace Vaulter\Release\Contract;

/**
 * The release bases of the BC check (D14/D18, knowledge examples-p6-p8#bc-bases): per release line the highest strict-SemVer
 * STABLE tag of the line's major that peels to a commit which is an ancestor of the candidate and is NOT the candidate under any
 * tag alias (annotated, lightweight, prerelease). A tag on a tree/blob is no base. Build-metadata ties and two tags of one
 * version are rejected, never ordered by ref order. The trusted ledger of known releases detects a deleted or moved tag and a
 * shallow/incomplete fetch — a `git tag --list` alone cannot know a tag it never saw. Local git only: no fetch, no network.
 */
final class ReleaseBases
{
    private const SEMVER = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    /** @return array{major: int, minor: int, patch: int, pre: ?string, build: ?string}|null null = not strict SemVer 2.0 */
    public static function semver(string $version): ?array
    {
        if (preg_match(self::SEMVER, $version, $m) !== 1) {
            return null;
        }

        return ['major' => (int) $m[1], 'minor' => (int) $m[2], 'patch' => (int) $m[3], 'pre' => ($m[4] ?? '') === '' ? null : $m[4], 'build' => ($m[5] ?? '') === '' ? null : $m[5]];
    }

    /**
     * Every strict-SemVer tag of a line that peels to a commit.
     *
     * @return list<array{tag: string, sha: string, version: array{major: int, minor: int, patch: int, pre: ?string, build: ?string}}>
     */
    public static function tags(string $root, string $prefix): array
    {
        $out = self::git($root, ['for-each-ref', '--format=%(refname:strip=2)%09%(objecttype)%09%(objectname)%09%(*objecttype)%09%(*objectname)', 'refs/tags']);
        $tags = [];
        foreach (array_filter(explode("\n", $out)) as $row) {
            [$name, $type, $oid, $peeledType, $peeled] = array_pad(explode("\t", $row), 5, '');
            $sha = $type === 'commit' ? $oid : ($type === 'tag' && $peeledType === 'commit' ? $peeled : null);
            if ($sha === null || !str_starts_with($name, $prefix) || ($version = self::semver(substr($name, strlen($prefix)))) === null) {
                continue; // a tag on a tree/blob or a nested tag is no base; a foreign tag is no release of this line
            }
            $tags[] = ['tag' => $name, 'sha' => $sha, 'version' => $version];
        }

        return $tags;
    }

    /**
     * @param  list<array{line?: string, tag: string, sha: string}>                                                                   $known the trusted ledger of this line
     * @return array{tag: string, sha: string, version: array{major: int, minor: int, patch: int, pre: ?string, build: ?string}}|null
     */
    public static function resolve(string $root, string $candidate, string $prefix, int $major, array $known): ?array
    {
        if (trim(self::git($root, ['rev-parse', '--is-shallow-repository'])) !== 'false') {
            throw new ContractError('the repository is shallow or incomplete: the release bases cannot be trusted');
        }
        if (trim(self::git($root, ['cat-file', '-t', $candidate])) !== 'commit') {
            throw new ContractError('the candidate is not a commit of this repository');
        }
        $tags = self::tags($root, $prefix);
        $byName = array_column($tags, 'sha', 'tag');
        foreach ($known as $release) {
            if (($byName[$release['tag']] ?? null) !== $release['sha']) {
                throw new ContractError("known release {$release['tag']} is missing or moved: deleted tag or incomplete fetch");
            }
        }
        $seen = [];
        $best = null;
        foreach ($tags as $tag) {
            $v = $tag['version'];
            if ($v['pre'] !== null || $v['major'] !== $major) {
                continue;
            }
            $key = "{$v['major']}.{$v['minor']}.{$v['patch']}";
            if (isset($seen[$key])) {
                throw new ContractError("ambiguous release records for {$key} ({$seen[$key]}, {$tag['tag']})");
            }
            $seen[$key] = $tag['tag'];
            if ($tag['sha'] === $candidate || !self::ancestor($root, $tag['sha'], $candidate)) {
                continue; // the candidate under ANY alias is never its own base
            }
            if ($best === null || [$v['minor'], $v['patch']] <=> [$best['version']['minor'], $best['version']['patch']] > 0) {
                $best = $tag;
            }
        }

        return $best;
    }

    private static function ancestor(string $root, string $ancestor, string $descendant): bool
    {
        return self::run($root, ['merge-base', '--is-ancestor', $ancestor, $descendant])[0] === 0;
    }

    private static function git(string $root, array $args): string
    {
        [$code, $out] = self::run($root, $args);
        if ($code !== 0) {
            throw new ContractError('git '.$args[0].' failed');
        }

        return $out;
    }

    /** @return array{int, string} */
    private static function run(string $root, array $args): array
    {
        $process = proc_open(['git', '-c', 'core.fsmonitor=false', '-C', $root, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
