<?php

declare(strict_types=1);

namespace Vaulter\Release\Contract;

/**
 * The identities adapter: the generated identities/{protocol,http,config,cli,schema}.json
 * compared item by item on the stable key id + owner, field by field on the contract fields each kind declares. Item order,
 * `source` refs, the `changes` ledger and `base` are no contract; an unknown root or item field, an unknown version or kind,
 * a duplicate id, an owner outside the inventory is an error (exit 2), never ignored.
 *
 * The current format carries no optional/required marker for an event payload: a new `data` key is REVIEW, not ADD. CLI
 * `exit_codes` are the symbolic codes the inventory found, not an exhaustive code-path proof — a change is REVIEW, and the
 * limitation stays a manual check.
 *
 * @phpstan-import-type ChangeRow from Change
 */
final class Identities
{
    /** kind => [root contract fields, item contract fields, item technical fields] */
    public const KINDS = [
        'protocol' => [[], ['attribute', 'call', 'calls', 'case', 'cast', 'class', 'config_key', 'data', 'exception', 'format', 'fqcns', 'manual', 'model', 'receipt', 'retryable', 'sinks', 'status', 'subject', 'via'], ['source']],
        'http' => [['flags', 'openapi_prefix'], ['default_registered', 'methods', 'middleware', 'name', 'openapi', 'requires', 'route_only', 'uri'], ['action', 'controller', 'source']],
        'config' => [['roots'], ['config_keys', 'default', 'defaults', 'env', 'registered_defaults', 'root', 'via'], ['source']],
        'cli' => [[], ['arguments', 'exit_codes', 'hidden', 'options', 'parse'], ['class', 'description', 'source']],
        'schema' => [['axes', 'canonical_preset', 'engines', 'presets'], ['columns', 'engines', 'logical', 'table', 'tables', 'variants'], ['migration', 'original_diff', 'source', 'sqlite_fk_diff']],
    ];

    /** Root fields that are no contract: the ledger of changes and the base it was written against. */
    private const ROOT_TECHNICAL = ['changes', 'base'];

    /** Middleware whose removal drops an authentication, authorization, module or abuse guard: a security BREAK. */
    private const SECURITY_MIDDLEWARE = '/^(?:auth\b|auth:|can:|ability:|abilities:|signed|verified|password\.confirm|throttle|vaulter\.module|.*\\\\(?:Authenticate|Authorize|EnsureEmailIsVerified|ValidateSignature)\w*$)/i';

    /**
     * @param  list<string>                                                                                $owners the packages of the inventory
     * @return array{kind: string, root: array<string, mixed>, items: array<string, array<string, mixed>>}
     */
    public static function load(string $json, string $kind, array $owners, string $where): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw new ContractError("{$where}: unknown identities kind {$kind}");
        }
        [$rootFields, $contract, $technical] = self::KINDS[$kind];
        $data = SnapshotManifest::decode($json, $where);
        SnapshotManifest::keys($data, ['version', 'kind', 'items', ...self::ROOT_TECHNICAL, ...$rootFields], ['version', 'kind', 'items'], $where);
        if ($data['version'] !== 1 || $data['kind'] !== $kind) {
            throw new ContractError("{$where}: unknown version or kind");
        }
        if (!is_array($data['items']) || !array_is_list($data['items'])) {
            throw new ContractError("{$where}: items must be a list");
        }
        $items = [];
        foreach ($data['items'] as $i => $item) {
            SnapshotManifest::keys($item, ['id', 'owner', ...$contract, ...$technical], ['id', 'owner'], "{$where} item #{$i}");
            if (!is_string($item['id']) || $item['id'] === '') {
                throw new ContractError("{$where} item #{$i}: invalid id");
            }
            if (!is_string($item['owner']) || !in_array($item['owner'], $owners, true)) {
                throw new ContractError("{$where} {$item['id']}: owner missing or outside the inventory");
            }
            if (isset($items[$item['id']])) {
                throw new ContractError("{$where}: duplicate id {$item['id']}");
            }
            $items[$item['id']] = array_diff_key($item, array_flip($technical));
        }
        ksort($items, SORT_STRING);

        return ['kind' => $kind, 'root' => array_intersect_key($data, array_flip($rootFields)), 'items' => $items];
    }

    /**
     * @param  array{kind: string, root: array<string, mixed>, items: array<string, array<string, mixed>>} $before
     * @param  array{kind: string, root: array<string, mixed>, items: array<string, array<string, mixed>>} $after
     * @return list<ChangeRow>
     */
    public static function compare(string $surface, array $before, array $after): array
    {
        $kind = $after['kind'];
        if ($before['kind'] !== $kind) {
            throw new ContractError("{$surface}: kind changed from {$before['kind']} to {$kind}");
        }
        $changes = [];
        $add = static function (string $subject, string $rule, string $classification, mixed $from, mixed $to, string $why) use (&$changes, $surface): void {
            $changes[] = Change::make($surface, $subject, $rule, $classification, $from, $to, "{$subject}: {$why}");
        };

        foreach (self::KINDS[$kind][0] as $field) {
            if (Change::canonical($before['root'][$field] ?? null) !== Change::canonical($after['root'][$field] ?? null)) {
                $add("{$kind}.{$field}", 'root-field', Change::REVIEW, $before['root'][$field] ?? null, $after['root'][$field] ?? null, 'a root contract field changed; it applies to every item.');
            }
        }
        foreach ($before['items'] as $id => $item) {
            $next = $after['items'][$id] ?? null;
            if ($next === null) {
                $add($id, 'identity-removed', Change::BREAK, $item, null, "the {$kind} identity is gone.");

                continue;
            }
            if ($next['owner'] !== $item['owner']) {
                $add($id, 'owner-changed', Change::BREAK, $item, $next, "moved from {$item['owner']} to {$next['owner']}: gone from the release line of its owner.");

                continue;
            }
            foreach (self::KINDS[$kind][1] as $field) {
                $old = $item[$field] ?? null;
                $new = $next[$field] ?? null;
                if (Change::canonical($old) === Change::canonical($new)) {
                    continue;
                }
                $handled = match ($kind) {
                    'protocol' => self::protocol($id, $field, $old, $new, $add),
                    'http' => self::http($id, $field, $old, $new, $add),
                    'cli' => self::cli($id, $field, $old, $new, $add),
                    'schema' => self::schema($id, $field, $old, $new, $add),
                    default => false,
                };
                if (!$handled) {
                    $rule = $kind === 'config' && in_array($field, ['default', 'defaults', 'env'], true) ? "config-{$field}" : "{$kind}-field";
                    $add("{$id}.{$field}", $rule, Change::REVIEW, $old, $new, "the {$field} changed (compared as structure, not as a string); needs migration or consumer evidence.");
                }
            }
        }
        foreach ($after['items'] as $id => $item) {
            if (isset($before['items'][$id])) {
                continue;
            }
            if ($kind === 'schema' && !str_starts_with($id, 'table:') && !self::newTable($item, $before, $after)) {
                $add($id, 'schema-existing-table', Change::REVIEW, null, $item, 'a new column, index or constraint of an existing table needs migration and upgrade evidence.');
            } else {
                $add($id, 'identity-added', Change::ADD, null, $item, "a new {$kind} identity.");
            }
        }

        return Change::sorted($changes);
    }

    /** @param callable(string, string, string, mixed, mixed, string): void $add */
    private static function protocol(string $id, string $field, mixed $old, mixed $new, callable $add): bool
    {
        if ($field === 'status') {
            $add("{$id}.status", 'problem-status', Change::BREAK, $old, $new, 'the HTTP status of a problem is part of what clients branch on.');

            return true;
        }
        if ($field === 'sinks') {
            $add("{$id}.sinks", 'event-sinks', Change::REVIEW, $old, $new, 'where an event is delivered (activity, external, journal) is semantics, not metadata.');

            return true;
        }
        if ($field !== 'data' || !is_array($old) || !is_array($new)) {
            return false;
        }
        foreach ($old as $key => $type) {
            if (!array_key_exists($key, $new)) {
                $add("{$id}.data.{$key}", 'payload-field-removed', Change::BREAK, $type, null, 'a payload field consumers read is gone.');
            } elseif (Change::canonical($new[$key]) !== Change::canonical($type)) {
                $add("{$id}.data.{$key}", 'payload-field-type', Change::BREAK, $type, $new[$key], 'the type of a payload field changed.');
            }
        }
        foreach ($new as $key => $type) {
            if (!array_key_exists($key, $old)) {
                $add("{$id}.data.{$key}", 'payload-field-added', Change::REVIEW, null, $type, 'the format has no optional marker, so a new key is not proven optional.');
            }
        }

        return true;
    }

    /** @param callable(string, string, string, mixed, mixed, string): void $add */
    private static function http(string $id, string $field, mixed $old, mixed $new, callable $add): bool
    {
        if ($field === 'uri') {
            $add("{$id}.uri", 'route-path', Change::BREAK, $old, $new, 'the path clients call changed.');

            return true;
        }
        if ($field === 'name') {
            $add("{$id}.name", 'route-name', Change::BREAK, $old, $new, 'hosts generate URLs by route name.');

            return true;
        }
        if (!in_array($field, ['methods', 'middleware'], true) || !is_array($old) || !is_array($new)) {
            return false;
        }
        $normal = static fn (array $list): array => array_values(array_unique(array_map(static fn (mixed $v): string => $field === 'methods' ? strtoupper((string) $v) : (string) $v, $list)));
        $removed = array_values(array_diff($normal($old), $normal($new)));
        $added = array_values(array_diff($normal($new), $normal($old)));
        if ($field === 'methods') {
            $removed !== [] && $add("{$id}.methods", 'route-method-removed', Change::BREAK, $old, $new, 'the methods '.implode(', ', $removed).' are gone.');
            $added !== [] && $add("{$id}.methods", 'route-method-added', Change::ADD, $old, $new, 'the methods '.implode(', ', $added).' are new.');

            return true;
        }
        foreach ($removed as $middleware) {
            preg_match(self::SECURITY_MIDDLEWARE, $middleware) === 1
                ? $add("{$id}.middleware.{$middleware}", 'security-middleware-removed', Change::BREAK, $old, $new, 'an authentication, authorization or guard middleware was removed.')
                : $add("{$id}.middleware.{$middleware}", 'middleware-removed', Change::REVIEW, $old, $new, 'a middleware was removed.');
        }
        $added !== [] && $add("{$id}.middleware", 'middleware-added', Change::REVIEW, $old, $new, 'new middleware '.implode(', ', $added).' can reject requests that passed.');
        $removed === [] && $added === [] && $add("{$id}.middleware", 'middleware-order', Change::REVIEW, $old, $new, 'the middleware order changed.');

        return true;
    }

    /** @param callable(string, string, string, mixed, mixed, string): void $add */
    private static function cli(string $id, string $field, mixed $old, mixed $new, callable $add): bool
    {
        if ($field === 'exit_codes') {
            $add("{$id}.exit_codes", 'cli-exit-codes', Change::REVIEW, $old, $new, 'the symbolic exit codes changed; the inventory is not an exhaustive code-path proof (manual check).');

            return true;
        }
        if (!in_array($field, ['options', 'arguments'], true) || !is_array($old) || !is_array($new)) {
            return false;
        }
        $by = static function (array $list): array {
            $out = [];
            foreach ($list as $entry) {
                unset($entry['description']); // help text, no contract
                $out[(string) ($entry['name'] ?? '')] = $entry;
            }

            return $out;
        };
        $b = $by($old);
        $a = $by($new);
        $what = $field === 'options' ? 'option' : 'argument';
        foreach ($b as $name => $entry) {
            if (!isset($a[$name])) {
                $add("{$id}.{$field}.{$name}", "cli-{$what}-removed", Change::BREAK, $entry, null, "the {$what} is gone; existing invocations fail.");
            } elseif (Change::canonical($a[$name]) !== Change::canonical($entry)) {
                $add("{$id}.{$field}.{$name}", "cli-{$what}-changed", Change::REVIEW, $entry, $a[$name], "the {$what} changed.");
            }
        }
        foreach ($a as $name => $entry) {
            if (!isset($b[$name])) {
                ($entry['required'] ?? false) === true
                    ? $add("{$id}.{$field}.{$name}", "cli-{$what}-required-added", Change::BREAK, null, $entry, "a new required {$what}; existing invocations fail.")
                    : $add("{$id}.{$field}.{$name}", "cli-{$what}-added", Change::ADD, null, $entry, "a new optional {$what}.");
            }
        }
        if (array_keys($b) !== array_keys($a) && $field === 'arguments' && array_intersect_key($b, $a) !== []) {
            $add("{$id}.arguments", 'cli-argument-order', Change::REVIEW, $old, $new, 'the positional order of the arguments changed.');
        }

        return true;
    }

    /** @param callable(string, string, string, mixed, mixed, string): void $add */
    private static function schema(string $id, string $field, mixed $old, mixed $new, callable $add): bool
    {
        if ($field === 'columns' && is_array($old) && is_array($new)) {
            // The column list of a table: by name, the order is no contract.
            foreach (array_diff($old, $new) as $column) {
                $add("{$id}.columns.{$column}", 'column-removed', Change::BREAK, $old, $new, 'a column of an existing table is gone.');
            }
            foreach (array_diff($new, $old) as $column) {
                $add("{$id}.columns.{$column}", 'column-added', Change::REVIEW, $old, $new, 'a new column of an existing table needs migration and upgrade evidence.');
            }

            return true;
        }
        if ($field !== 'engines' || !is_array($old) || !is_array($new)) {
            return false;
        }
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $engine) {
            $b = $old[$engine] ?? null;
            $a = $new[$engine] ?? null;
            if (Change::canonical($b) === Change::canonical($a)) {
                continue;
            }
            $tightened = is_array($b) && is_array($a) && ($b['nullable'] ?? null) === true && ($a['nullable'] ?? null) === false;
            $add("{$id}.engines.{$engine}", $tightened ? 'column-not-null' : 'schema-engine', Change::REVIEW, $b, $a, $tightened ? 'a nullable column became NOT NULL: existing rows and writers can fail.' : "the {$engine} definition changed.");
        }

        return true;
    }

    /**
     * A new schema item belongs to a new table when its table has no identity before and one after.
     *
     * @param array<string, mixed>                                                                        $item
     * @param array{kind: string, root: array<string, mixed>, items: array<string, array<string, mixed>>} $before
     * @param array{kind: string, root: array<string, mixed>, items: array<string, array<string, mixed>>} $after
     */
    private static function newTable(array $item, array $before, array $after): bool
    {
        $table = $item['table'] ?? null;

        return is_string($table) && !isset($before['items']["table:{$table}"]) && isset($after['items']["table:{$table}"]);
    }
}
