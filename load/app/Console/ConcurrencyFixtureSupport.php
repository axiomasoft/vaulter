<?php

declare(strict_types=1);

namespace Vaulter\Load\Console;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Disposable P5.2 fixtures: small CAS payloads, 1k-node trees, dirty-count
 * profiles, and post-run integrity checks. The 1m profile is a cardinality
 * manifest, not a tier-L seed.
 */
final class ConcurrencyFixtureSupport
{
    public const string SCHEMA_VERSION = 'p5-concurrency-fixtures/v1';

    public const int COLLISION_CANDIDATE_LIMIT = 250_000;

    public const int TREE_NODE_COUNT = 1000;

    public const int DIRTY_20K = 20_000;

    public const int DIRTY_1M = 1_000_000;

    public const int MAX_PAYLOAD_BYTES = 4096;

    public static function fixturesRoot(): string
    {
        return storage_path('app/p5-concurrency-fixtures');
    }

    public static function fixtureDirectory(string $runPrefix, string $fixtureId): string
    {
        return self::fixturesRoot().'/'.self::sanitizeToken($runPrefix).'/'.self::sanitizeToken($fixtureId);
    }

    public static function sanitizeToken(string $value): string
    {
        $token = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?? '';

        return trim($token, '-') !== '' ? trim($token, '-') : 'default';
    }

    /**
     * @return array{sha256: string, hashtext: int, bytes: int, path: string}
     */
    public static function writePayload(string $path, string $bytes): array
    {
        if (strlen($bytes) > self::MAX_PAYLOAD_BYTES) {
            throw new RuntimeException('P5 payloads stay bounded; multi-GiB bodies belong to P4.');
        }

        File::ensureDirectoryExists(dirname($path));
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException("Unable to write payload [{$path}].");
        }

        return self::describePayload($path);
    }

    /**
     * @return array{sha256: string, hashtext: int, bytes: int, path: string, scope_key: string}
     */
    public static function describePayload(string $path, string $scope = 'p5'): array
    {
        $size = filesize($path);
        if ($size === false || $size > self::MAX_PAYLOAD_BYTES) {
            throw new RuntimeException("Payload [{$path}] is missing or larger than the P5 bound.");
        }

        $raw = file_get_contents($path, false, null, 0, self::MAX_PAYLOAD_BYTES);
        if ($raw === false) {
            throw new RuntimeException("Unable to read payload [{$path}].");
        }

        $sha = hash('sha256', $raw);
        $scopeKey = $scope.'|'.$sha;

        return [
            'sha256' => $sha,
            'hashtext' => self::hashtext($scopeKey),
            'bytes' => strlen($raw),
            'path' => $path,
            'scope_key' => $scopeKey,
        ];
    }

    public static function hashtext(string $key): int
    {
        $local = ConcurrencyHashtext::int4($key);

        if (self::postgresAvailable()) {
            $row = DB::selectOne('select hashtext(?) as h', [$key]);
            $remote = (int) $row->h;
            if ($remote !== $local) {
                throw new RuntimeException('PostgreSQL 16 hashtext disagreed with the local int4 function.');
            }

            return $remote;
        }

        return $local;
    }

    /**
     * First distinct-sha256 pair whose hashtext(scope|sha256) collides.
     * Stops at 250k candidates instead of inventing a pair.
     *
     * @return array{scope: string, candidates_seen: int, left: array<string, mixed>, right: array<string, mixed>}
     */
    public static function searchCollision(string $scope, int $maxCandidates = self::COLLISION_CANDIDATE_LIMIT): array
    {
        if ($maxCandidates < 2) {
            throw new RuntimeException('Collision search needs at least two candidates.');
        }

        /** @var array<int, array{index: int, sha256: string, bytes: string}> $seen */
        $seen = [];

        for ($index = 0; $index < $maxCandidates; $index++) {
            $bytes = self::candidateBytes($index);
            $sha = hash('sha256', $bytes);
            $key = ConcurrencyHashtext::int4($scope.'|'.$sha);

            if (isset($seen[$key]) && $seen[$key]['sha256'] !== $sha) {
                return [
                    'scope' => $scope,
                    'candidates_seen' => $index + 1,
                    'left' => [
                        'index' => $seen[$key]['index'],
                        'sha256' => $seen[$key]['sha256'],
                        'bytes' => $seen[$key]['bytes'],
                        'hashtext' => $key,
                    ],
                    'right' => [
                        'index' => $index,
                        'sha256' => $sha,
                        'bytes' => $bytes,
                        'hashtext' => $key,
                    ],
                ];
            }

            $seen[$key] = ['index' => $index, 'sha256' => $sha, 'bytes' => $bytes];
        }

        throw new RuntimeException(
            "No int4 hashtext collision after {$maxCandidates} distinct payloads; refusing to fabricate a pair.",
        );
    }

    public static function candidateBytes(int $index): string
    {
        return 'p5v1|'.$index.'|'.str_repeat('x', 16);
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildManifest(string $fixtureId, string $runPrefix, string $scope = 'p5'): array
    {
        $directory = self::fixtureDirectory($runPrefix, $fixtureId);
        $collision = self::searchCollision($scope);
        $normalLeft = self::candidateBytes(0);
        $normalRight = self::candidateBytes(1);

        $payloads = [
            'normal-a' => self::writePayload($directory.'/normal-a.bin', $normalLeft),
            'normal-b' => self::writePayload($directory.'/normal-b.bin', $normalRight),
            'collision-a' => self::writePayload($directory.'/collision-a.bin', $collision['left']['bytes']),
            'collision-b' => self::writePayload($directory.'/collision-b.bin', $collision['right']['bytes']),
        ];

        if ($payloads['normal-a']['sha256'] === $payloads['normal-b']['sha256']
            || $payloads['normal-a']['hashtext'] === $payloads['normal-b']['hashtext']) {
            throw new RuntimeException('Normal CAS control must use distinct sha256 and distinct int4 keys.');
        }

        if ($payloads['collision-a']['sha256'] === $payloads['collision-b']['sha256']
            || $payloads['collision-a']['hashtext'] !== $payloads['collision-b']['hashtext']) {
            throw new RuntimeException('CAS collision pair must keep distinct bytes and an equal int4 key.');
        }

        $paths = [
            $directory.'/manifest.json',
            $payloads['normal-a']['path'],
            $payloads['normal-b']['path'],
            $payloads['collision-a']['path'],
            $payloads['collision-b']['path'],
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'fixture_id' => $fixtureId,
            'run_prefix' => $runPrefix,
            'snapshot_id' => self::sanitizeToken($runPrefix).'/'.self::sanitizeToken($fixtureId),
            'scope' => $scope,
            'hashtext_backend' => self::postgresAvailable() ? 'postgresql-16' : 'postgresql-16-algorithm',
            'payloads' => $payloads,
            'sessions' => self::sessionPlan($fixtureId),
            'trees' => [
                'disjoint' => self::treePlan(disjoint: true),
                'overlapping' => self::treePlan(disjoint: false),
            ],
            'dirty' => [
                'dirty-20k' => self::dirtyProfile(self::DIRTY_20K, targetedProbe: false),
                'dirty-1m' => self::dirtyProfile(self::DIRTY_1M, targetedProbe: true),
            ],
            'cleanup' => ['paths' => $paths],
            'collision_candidates_seen' => $collision['candidates_seen'],
        ];
    }

    /**
     * @param  array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public static function verifyManifest(array $manifest): array
    {
        $scope = (string) ($manifest['scope'] ?? 'p5');
        $payloads = $manifest['payloads'] ?? null;
        if (!is_array($payloads)) {
            throw new RuntimeException('Fixture manifest is missing payloads.');
        }

        foreach ($payloads as $name => $described) {
            if (!is_array($described) || !isset($described['path'])) {
                throw new RuntimeException("Payload [{$name}] is incomplete.");
            }

            $fresh = self::describePayload((string) $described['path'], $scope);
            foreach (['sha256', 'hashtext', 'bytes'] as $field) {
                if ($fresh[$field] !== $described[$field]) {
                    throw new RuntimeException("Payload [{$name}] {$field} does not match the bytes on disk.");
                }
            }
        }

        $left = $payloads['collision-a'];
        $right = $payloads['collision-b'];
        if ($left['sha256'] === $right['sha256'] || $left['hashtext'] !== $right['hashtext']) {
            throw new RuntimeException('Collision pair failed re-verification.');
        }

        $normalA = $payloads['normal-a'];
        $normalB = $payloads['normal-b'];
        if ($normalA['sha256'] === $normalB['sha256'] || $normalA['hashtext'] === $normalB['hashtext']) {
            throw new RuntimeException('Normal CAS control failed re-verification.');
        }

        self::assertDirtyProfiles($manifest);
        self::assertTreePlans($manifest);

        return $manifest;
    }

    /**
     * @param  array<string, mixed> $report
     * @return list<string>
     */
    public static function integrityDefects(array $report): array
    {
        $defects = [];

        if (isset($report['blobs']) && is_array($report['blobs'])) {
            $defects = array_merge($defects, self::blobDefects($report['blobs']));
        }

        if (isset($report['upload']) && is_array($report['upload'])) {
            $defects = array_merge($defects, self::uploadDefects($report['upload']));
        }

        if (isset($report['tree']) && is_array($report['tree'])) {
            $parents = $report['tree']['parents'] ?? [];
            $expected = (int) ($report['tree']['expected_count'] ?? 0);
            if (is_array($parents)) {
                $defects = array_merge($defects, self::treeDefects($parents, $expected));
            }
        }

        if (isset($report['sweep']) && is_array($report['sweep'])) {
            $defects = array_merge($defects, self::sweepDefects($report['sweep']));
        }

        return array_values(array_unique($defects));
    }

    /**
     * @param  list<array<string, mixed>> $blobs
     * @return list<string>
     */
    public static function blobDefects(array $blobs): array
    {
        $byId = [];
        $defects = [];

        foreach ($blobs as $blob) {
            $sha = (string) ($blob['sha256'] ?? '');
            $bytesSha = (string) ($blob['bytes_sha256'] ?? '');
            $id = (string) ($blob['blob_id'] ?? '');

            if ($sha === '' || $sha !== $bytesSha) {
                $defects[] = 'blob-aliasing';
            }

            if ($id !== '' && isset($byId[$id]) && $byId[$id] !== $sha) {
                $defects[] = 'blob-aliasing';
            }

            if ($id !== '') {
                $byId[$id] = $sha;
            }
        }

        return array_values(array_unique($defects));
    }

    /**
     * @param  array<string, mixed> $upload
     * @return list<string>
     */
    public static function uploadDefects(array $upload): array
    {
        $received = (int) ($upload['received_bytes'] ?? -1);
        $staged = (int) ($upload['staged_bytes'] ?? -2);
        $status = (string) ($upload['status'] ?? '');
        $total = (int) ($upload['total_bytes'] ?? $received);

        if ($received !== $staged) {
            return ['torn-upload'];
        }

        if ($status === 'committed' && $staged !== $total) {
            return ['torn-upload'];
        }

        return [];
    }

    /**
     * @param  array<string, string|null> $parents
     * @return list<string>
     */
    public static function treeDefects(array $parents, int $expectedCount): array
    {
        $defects = [];

        if (count($parents) !== $expectedCount) {
            $defects[] = 'lost-node';
        }

        foreach (array_keys($parents) as $start) {
            $seen = [];
            $current = (string) $start;

            while (true) {
                if (isset($seen[$current])) {
                    $defects[] = 'tree-cycle';
                    break;
                }

                $seen[$current] = true;

                if (!array_key_exists($current, $parents)) {
                    $defects[] = 'lost-node';
                    break;
                }

                $parent = $parents[$current];
                if ($parent === null || $parent === '') {
                    break;
                }

                $current = (string) $parent;
            }
        }

        return array_values(array_unique($defects));
    }

    /**
     * @param  array<string, mixed> $sweep
     * @return list<string>
     */
    public static function sweepDefects(array $sweep): array
    {
        $before = is_array($sweep['before'] ?? null) ? $sweep['before'] : [];
        $after = is_array($sweep['after'] ?? null) ? $sweep['after'] : [];
        $live = (bool) ($before['live'] ?? false);
        $staged = (int) ($before['staged_bytes'] ?? 0);
        $afterStatus = (string) ($after['status'] ?? '');

        if ($live && $staged > 0 && $afterStatus === 'expired') {
            return ['swept-live-session'];
        }

        return [];
    }

    /**
     * @param list<string> $paths
     */
    public static function cleanupPaths(array $paths, string $directory): void
    {
        $root = realpath(self::fixturesRoot());
        $directoryReal = realpath($directory) ?: $directory;

        foreach ($paths as $path) {
            $real = realpath($path) ?: $path;
            if ($root !== false && !str_starts_with($real, $root.DIRECTORY_SEPARATOR) && $real !== $root) {
                throw new RuntimeException("Refusing to delete [{$path}] outside the fixture root.");
            }

            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($directoryReal)) {
            File::deleteDirectory($directoryReal);
        }
    }

    public static function postgresAvailable(): bool
    {
        try {
            return DB::connection()->getDriverName() === 'pgsql';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function sessionPlan(string $fixtureId): array
    {
        $token = self::sanitizeToken($fixtureId);

        return [
            'tus' => [
                'same' => ['upload_id' => "tus-same-{$token}", 'resource' => 0],
                'different' => ['upload_id_pattern' => "tus-diff-{$token}-{actor}", 'resource' => 'actor'],
            ],
            's3' => [
                'same' => ['upload_id' => "s3-same-{$token}", 'resource' => 0],
                'different' => ['upload_id_pattern' => "s3-diff-{$token}-{actor}", 'resource' => 'actor'],
            ],
            'expired' => [
                'status' => 'expired',
                'live' => false,
                'staged_bytes' => 0,
                'received_bytes' => 0,
            ],
            'live' => [
                'status' => 'uploading',
                'live' => true,
                'staged_bytes' => 32,
                'received_bytes' => 32,
            ],
            'collab' => [
                'issuance' => 'burst',
                'document_id' => "doc-{$token}",
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function treePlan(bool $disjoint): array
    {
        $parents = [];

        for ($node = 1; $node <= self::TREE_NODE_COUNT; $node++) {
            if ($node === 1 || $node === 501) {
                $parents[(string) $node] = null;

                continue;
            }

            $parents[(string) $node] = (string) ($node - 1);
        }

        $initial = $parents;

        if ($disjoint) {
            $parents['250'] = '800';

            return [
                'node_count' => self::TREE_NODE_COUNT,
                'initial_parents' => $initial,
                'parents_after' => $parents,
                'moves' => [['node' => '250', 'into' => '800']],
                'restore_each_repetition' => true,
            ];
        }

        return [
            'node_count' => self::TREE_NODE_COUNT,
            'initial_parents' => $initial,
            'parents_after' => $initial,
            'moves' => [
                ['node' => '2', 'into' => '400', 'expect' => 'unchanged-no-cycle'],
                ['node' => '10', 'into' => '900', 'expect' => 'overlap-with-node-2-subtree'],
            ],
            'restore_each_repetition' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function dirtyProfile(int $rows, bool $targetedProbe): array
    {
        return [
            'rows' => $rows,
            'seeded' => false,
            'tier_l_claim' => false,
            'targeted_cardinality_probe' => $targetedProbe,
            'plane' => 'dirty-cardinality-probe',
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function assertDirtyProfiles(array $manifest): void
    {
        $dirty = $manifest['dirty'] ?? null;
        if (!is_array($dirty)) {
            throw new RuntimeException('Fixture manifest is missing dirty profiles.');
        }

        $twenty = $dirty['dirty-20k']['rows'] ?? null;
        $million = $dirty['dirty-1m']['rows'] ?? null;

        if ($twenty !== self::DIRTY_20K || $million !== self::DIRTY_1M) {
            throw new RuntimeException('Dirty profiles must be 20000 and 1000000 rows.');
        }

        if (($dirty['dirty-1m']['tier_l_claim'] ?? true) !== false || ($dirty['dirty-1m']['seeded'] ?? true) !== false) {
            throw new RuntimeException('The 1m dirty manifest is a targeted probe and must not claim tier L or a seed.');
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function assertTreePlans(array $manifest): void
    {
        $trees = $manifest['trees'] ?? null;
        if (!is_array($trees)) {
            throw new RuntimeException('Fixture manifest is missing tree plans.');
        }

        foreach (['disjoint', 'overlapping'] as $name) {
            $tree = $trees[$name] ?? null;
            if (!is_array($tree)) {
                throw new RuntimeException("Tree [{$name}] is missing.");
            }

            $initial = $tree['initial_parents'] ?? null;
            $after = $tree['parents_after'] ?? null;
            if (!is_array($initial) || !is_array($after)) {
                throw new RuntimeException("Tree [{$name}] is missing parent maps.");
            }

            if (count($initial) !== self::TREE_NODE_COUNT || count($after) !== self::TREE_NODE_COUNT) {
                throw new RuntimeException("Tree [{$name}] must contain 1000 nodes.");
            }

            if (($tree['restore_each_repetition'] ?? false) !== true) {
                throw new RuntimeException("Tree [{$name}] must restore fixture state per repetition.");
            }

            $initialDefects = self::treeDefects($initial, self::TREE_NODE_COUNT);
            $afterDefects = self::treeDefects($after, self::TREE_NODE_COUNT);
            if ($initialDefects !== [] || $afterDefects !== []) {
                throw new RuntimeException("Tree [{$name}] plan is not acyclic or lost a node.");
            }
        }
    }
}
