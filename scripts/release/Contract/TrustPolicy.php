<?php

declare(strict_types=1);

namespace Vaulter\Release\Contract;

/**
 * The trusted policy and the exact exceptions of the BC check. The policy and
 * the exceptions come from a trusted revision T, never from the candidate source S: a policy revision equal to S, or
 * exceptions that differ from the trusted ones, are self-authorizing and rejected. An exception suppresses a change only when
 * ALL its fields match, it has not expired, its decision is in the trusted ledger AND an external approval names the exact S,
 * the policy digest, the exceptions digest and the tool closure digest — an approval of S1 is void for S2. The ledger is audit,
 * not authority. initial_bootstrap, no_predecessor and self_baseline are never a BC pass; only an external approved_initial_release
 * admits a first release, and it never masks a missing known tag.
 *
 * @phpstan-import-type ChangeRow from Change
 */
final class TrustPolicy
{
    public const FORMAT = 'vaulter.bc-trust-policy';

    public const EXCEPTIONS_FORMAT = 'vaulter.bc-exceptions';

    private const EXCEPTION_KEYS = ['surface', 'subject', 'rule', 'classification', 'release_line', 'before_hash', 'after_hash', 'decision', 'reason', 'expiry'];

    /**
     * lines: line => {prefix, major}; known_releases: the ledger {line, tag, sha}; decisions: the approved PLAN4-D# ids;
     * approvals: external {kind: exceptions|initial_release, candidate_sha, policy_digest, ...}.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $json, string $where = 'policy'): array
    {
        $data = SnapshotManifest::decode($json, $where);
        SnapshotManifest::keys($data, ['format', 'version', 'lines', 'known_releases', 'decisions', 'approvals'], ['format', 'version', 'lines', 'known_releases', 'decisions', 'approvals'], $where);
        if ($data['format'] !== self::FORMAT || $data['version'] !== 1) {
            throw new ContractError("{$where}: unknown policy format or version");
        }
        if (!is_array($data['lines']) || $data['lines'] === []) {
            throw new ContractError("{$where}: lines must be a non-empty map");
        }
        foreach ($data['lines'] as $line => $def) {
            SnapshotManifest::keys($def, ['prefix', 'major'], ['prefix', 'major'], "{$where}: line {$line}");
            if (!is_string($def['prefix']) || !is_int($def['major']) || $def['major'] < 0) {
                throw new ContractError("{$where}: line {$line}: invalid prefix or major");
            }
        }
        foreach ($data['known_releases'] as $r) {
            SnapshotManifest::keys($r, ['line', 'tag', 'sha'], ['line', 'tag', 'sha'], "{$where}: known release");
            if (!isset($data['lines'][$r['line']]) || preg_match('/^[0-9a-f]{40}$/', (string) $r['sha']) !== 1) {
                throw new ContractError("{$where}: known release {$r['tag']}: unknown line or invalid sha");
            }
        }
        foreach ($data['approvals'] as $a) {
            if (!is_array($a) || !in_array($a['kind'] ?? null, ['exceptions', 'initial_release'], true) || preg_match('/^[0-9a-f]{40}$/', (string) ($a['candidate_sha'] ?? '')) !== 1) {
                throw new ContractError("{$where}: invalid approval");
            }
        }

        return $data;
    }

    /** The digest of the policy without its approvals (an approval names this digest). */
    public static function digest(array $policy): string
    {
        unset($policy['approvals']);

        return (string) Change::hash($policy);
    }

    /**
     * @param  array<string, mixed>        $policy
     * @return list<array<string, string>> exact exceptions
     */
    public static function exceptions(string $json, array $policy, string $where = 'exceptions'): array
    {
        $data = SnapshotManifest::decode($json, $where);
        SnapshotManifest::keys($data, ['format', 'version', 'exceptions'], ['format', 'version', 'exceptions'], $where);
        if ($data['format'] !== self::EXCEPTIONS_FORMAT || $data['version'] !== 1 || !is_array($data['exceptions']) || !array_is_list($data['exceptions'])) {
            throw new ContractError("{$where}: unknown format or version");
        }
        foreach ($data['exceptions'] as $i => $e) {
            SnapshotManifest::keys($e, self::EXCEPTION_KEYS, self::EXCEPTION_KEYS, "{$where}: exception #{$i}");
            foreach (self::EXCEPTION_KEYS as $key) {
                if (!is_string($e[$key]) && !($e[$key] === null && str_ends_with($key, '_hash')) || $e[$key] === '' || is_string($e[$key]) && str_contains($e[$key], '*')) {
                    throw new ContractError("{$where}: exception #{$i}: {$key} must be an exact value (no wildcard)");
                }
            }
            if (!in_array($e['classification'], [Change::BREAK, Change::REVIEW], true) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $e['expiry']) !== 1 || !in_array($e['decision'], $policy['decisions'], true)) {
                throw new ContractError("{$where}: exception #{$i}: classification, expiry or decision is not approved by the trusted ledger");
            }
        }

        return $data['exceptions'];
    }

    /**
     * Drops the changes covered by an exact, unexpired exception that an external approval binds to this candidate.
     *
     * @param  list<ChangeRow>                                               $changes    with `release_line`
     * @param  list<array<string, string>>                                   $exceptions
     * @param  array<string, string>                                         $digests    candidate_sha, policy_digest, exceptions_digest, tool_digest
     * @param  array<string, mixed>                                          $policy
     * @return array{remaining: list<array<string, mixed>>, suppressed: int}
     */
    public static function apply(array $changes, array $exceptions, array $policy, array $digests, string $today): array
    {
        $approved = false;
        foreach ($policy['approvals'] as $a) {
            $approved = $approved || $a['kind'] === 'exceptions' && array_diff_assoc($digests, $a) === [];
        }
        $remaining = [];
        $suppressed = 0;
        foreach ($changes as $change) {
            $hit = false;
            foreach ($approved ? $exceptions : [] as $e) {
                $hit = $hit || $e['expiry'] >= $today && array_diff_assoc(array_intersect_key($e, array_flip(['surface', 'subject', 'rule', 'classification', 'release_line', 'before_hash', 'after_hash'])), $change) === [];
            }
            $hit ? $suppressed++ : $remaining[] = $change;
        }

        return ['remaining' => $remaining, 'suppressed' => $suppressed];
    }

    /** @param array<string, mixed> $policy */
    public static function initialReleaseApproved(array $policy, string $candidate, string $line): bool
    {
        foreach ($policy['approvals'] as $a) {
            if ($a['kind'] === 'initial_release' && $a['candidate_sha'] === $candidate && ($a['policy_digest'] ?? null) === self::digest($policy) && ($a['line'] ?? null) === $line) {
                return true;
            }
        }

        return false;
    }
}
