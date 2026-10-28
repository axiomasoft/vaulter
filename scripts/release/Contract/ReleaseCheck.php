<?php

declare(strict_types=1);

namespace Vaulter\Release\Contract;

/**
 * One BC check run (D14/D18): the trusted policy picks the base of every release line from the candidate repository's own git
 * data, the manifests of BOTH the base and the candidate are validated and their surfaces compared by the adapters, and the exact
 * approved exceptions are applied. Exit 0 only when every line is compared (or has an external approved_initial_release) and
 * nothing blocks; 1 for BREAK/REVIEW or a line without a predecessor (initial_bootstrap/no_predecessor is never a BC pass);
 * 2 for an untrusted input. Nothing of the candidate is executed or fetched.
 *
 * @phpstan-import-type ChangeRow from Change
 */
final class ReleaseCheck
{
    /**
     * @param  string                                                                                                             $root      the candidate repository (data only)
     * @param  string                                                                                                             $candidate the candidate source SHA S
     * @param  string                                                                                                             $policyRev the trusted revision T the policy and exceptions were read from
     * @param  array<string, mixed>                                                                                               $policy    parsed trusted policy
     * @return array{exit: int, lines: array<string, array<string, mixed>>, changes: list<array<string, mixed>>, suppressed: int}
     */
    public static function run(string $root, string $candidate, string $policyRev, array $policy, string $exceptionsJson, string $toolDigest, string $today): array
    {
        try {
            if (preg_match('/^[0-9a-f]{40}$/', $candidate) !== 1 || preg_match('/^[0-9a-f]{40}$/', $policyRev) !== 1) {
                throw new ContractError('candidate and trusted policy revision must be full SHAs');
            }
            if ($policyRev === $candidate) {
                throw new ContractError('self-authorizing: the policy revision is the candidate source');
            }
            $head = SourceReader::revision($root, $candidate);
            $candidateExceptions = $head->read('release/bc-exceptions.json');
            if ($candidateExceptions !== null && $candidateExceptions !== $exceptionsJson) {
                throw new ContractError('self-authorizing: the candidate exceptions differ from the trusted exceptions');
            }
            $exceptions = TrustPolicy::exceptions($exceptionsJson, $policy);
            $headManifestJson = $head->read(SnapshotManifest::PATH);
            if ($headManifestJson === null) {
                throw new ContractError('the candidate manifest is missing');
            }
            $headManifest = SnapshotManifest::parse($headManifestJson);
            $manifestLines = array_keys($headManifest['release_lines']);
            $policyLines = array_keys($policy['lines']);
            sort($manifestLines, SORT_STRING);
            sort($policyLines, SORT_STRING);
            if ($manifestLines !== $policyLines) {
                throw new ContractError('the release lines of the candidate manifest ('.implode(', ', $manifestLines).') differ from the trusted policy ('.implode(', ', $policyLines).')');
            }
            $lines = [];
            $changes = [];
            foreach ($policy['lines'] as $line => $def) {
                $known = array_values(array_filter($policy['known_releases'], static fn (array $r): bool => $r['line'] === $line));
                $base = ReleaseBases::resolve($root, $candidate, $def['prefix'], $def['major'], $known);
                if ($base === null) {
                    $lines[$line] = ['admission' => TrustPolicy::initialReleaseApproved($policy, $candidate, $line) ? 'approved_initial_release' : 'initial_bootstrap', 'base' => null];

                    continue;
                }
                $baseReader = SourceReader::revision($root, $base['sha']);
                $baseManifestJson = $baseReader->read(SnapshotManifest::PATH);
                if ($baseManifestJson === null) {
                    throw new ContractError("the base manifest of {$base['tag']} is missing");
                }
                foreach (self::compare($line, SnapshotManifest::parse($baseManifestJson), $headManifest, $baseReader, $head) as $change) {
                    $changes[] = $change + ['release_line' => $line];
                }
                $lines[$line] = ['admission' => 'compared', 'base' => $base['tag']];
            }
            $applied = TrustPolicy::apply($changes, $exceptions, $policy, ['candidate_sha' => $candidate, 'policy_digest' => TrustPolicy::digest($policy), 'exceptions_digest' => hash('sha256', $exceptionsJson), 'tool_digest' => $toolDigest], $today);
            $notPass = array_filter($lines, static fn (array $l): bool => !in_array($l['admission'], ['compared', 'approved_initial_release'], true));

            return ['exit' => $applied['remaining'] !== [] || $notPass !== [] ? 1 : 0, 'lines' => $lines, 'changes' => $applied['remaining'], 'suppressed' => $applied['suppressed']];
        } catch (ContractError $e) {
            return ['exit' => 2, 'lines' => [], 'changes' => [['error' => $e->getMessage()]], 'suppressed' => 0];
        }
    }

    /** The sha256 of the trusted tool closure: every Contract class and the CLI entrypoint. */
    public static function toolDigest(string $releaseDir): string
    {
        $approved = getenv('BC_TOOL_CLOSURE_SHA256');
        if (is_string($approved) && preg_match('/^[a-f0-9]{64}$/', $approved) === 1) {
            return $approved;
        }
        $files = [];
        foreach (array_merge(glob($releaseDir.'/Contract/*.php') ?: [], [$releaseDir.'/bc-check.php']) as $file) {
            $files[basename($file)] = hash_file('sha256', $file);
        }
        ksort($files);

        return (string) Change::hash($files);
    }

    /**
     * The surfaces of one line, both sides validated: a surface gone from the head, a missing required input and an unknown
     * adapter are errors. `pending` surfaces (stale engine snapshots) are not compared.
     *
     * @param  array<string, mixed> $base
     * @param  array<string, mixed> $head
     * @return list<ChangeRow>
     */
    private static function compare(string $line, array $base, array $head, SourceReader $baseReader, SourceReader $headReader): array
    {
        $changes = [];
        $surfaces = SnapshotManifest::union($base, $head);
        $current = array_filter($surfaces, static fn (array $s): bool => $s['status'] === 'current');
        // The PHP graph is loaded from EVERY current php-surface of EVERY line before the per-line filter: a root of one line
        // may extend a class of another (collaboration → core), and an ancestor outside the line is resolved, not unresolved.
        $php = ['base' => [], 'head' => []];
        $declared = [];
        foreach ($current as $id => $surface) {
            if ($surface['adapter'] === 'php-surface') {
                $files = ['base' => SnapshotManifest::collect($surface, $baseReader), 'head' => SnapshotManifest::collect($surface, $headReader)];
                $php['base'] += $files['base'];
                $php['head'] += $files['head'];
                $declared[$id] = $files['base'] + $files['head'];
            }
        }
        $before = $php['base'] === [] ? [] : PhpSurface::load($php['base']);
        $after = $php['head'] === [] ? [] : PhpSurface::load($php['head']);
        $own = array_filter($current, static fn (array $s): bool => $s['release_line'] === $line || $s['release_line'] === '*');
        foreach ($own as $id => $surface) {
            if ($surface['adapter'] === 'php-surface') {
                $changes = [...$changes, ...PhpSurface::compare($id, $before, $after, PhpSurface::declared($declared[$id]))];

                continue;
            }
            $files = ['base' => SnapshotManifest::collect($surface, $baseReader), 'head' => SnapshotManifest::collect($surface, $headReader)];
            if (array_keys($files['base']) !== array_keys($files['head'])) {
                throw new ContractError("surface {$id}: the set of snapshot files changed between base and head");
            }
            if ($surface['adapter'] === 'npm-surface') {
                $changes = [...$changes, ...self::npm($id, $files['base'], $files['head'])];

                continue;
            }
            foreach ($files['head'] as $path => $json) {
                $changes = [...$changes, ...($surface['adapter'] === 'identities'
                    ? Identities::compare($id, Identities::load($files['base'][$path], basename($path, '.json'), self::owners($base), $path), Identities::load($json, basename($path, '.json'), self::owners($head), $path))
                    : SchemaDdl::compare($id, SchemaDdl::load($files['base'][$path], $path), SchemaDdl::load($json, $path)))];
            }
        }

        return Change::sorted($changes);
    }

    /**
     * An npm surface: one API Extractor report per exported entrypoint plus exactly one components.surface.txt (the props/emits
     * of the .vue components, which the reports do not carry). Any other shape is an error, never an empty comparison.
     *
     * @param  array<string, string> $base path => bytes
     * @param  array<string, string> $head path => bytes
     * @return list<ChangeRow>
     */
    private static function npm(string $id, array $base, array $head): array
    {
        $reports = array_values(array_filter(array_keys($head), static fn (string $p): bool => str_ends_with($p, '.api.md')));
        $components = array_values(array_filter(array_keys($head), static fn (string $p): bool => basename($p) === 'components.surface.txt'));
        if ($reports === [] || count($components) !== 1 || count($reports) + 1 !== count($head)) {
            throw new ContractError("surface {$id}: an npm surface needs API reports (*.api.md) and exactly one components.surface.txt");
        }
        $changes = [];
        foreach ($reports as $i => $path) {
            $load = static fn (array $files): array => NpmSurface::load($files[$path], $i === 0 ? $files[$components[0]] : '', $path);
            $changes = [...$changes, ...NpmSurface::compare($id, $load($base), $load($head))];
        }

        return $changes;
    }

    /** @param array<string, mixed> $manifest */
    private static function owners(array $manifest): array
    {
        return array_values(array_unique(array_merge(...array_values($manifest['release_lines']))));
    }
}
