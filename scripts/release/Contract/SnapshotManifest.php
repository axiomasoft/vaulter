<?php

declare(strict_types=1);

namespace Vaulter\Release\Contract;

use JsonException;

/**
 * release/contract-snapshots.json: the versioned list of contract surfaces — `id`, `adapter` with its `schema_version`,
 * `owner` and `release_line` (or `*` for both: each item carries its owner), the `inputs` (paths or globs), `required`,
 * `status` (`current`, or `pending` for a stale engine snapshot that is never taken for the current schema) and `coverage`.
 * The surfaces checked are the union of base and head: head cannot drop a surface or narrow it to nothing.
 *
 * @phpstan-type SurfaceEntry array{id: string, adapter: string, schema_version: int, owner: string, release_line: string, inputs: list<string>, required: bool, status: string, coverage: string}
 * @phpstan-type Manifest array{release_lines: array<string, list<string>>, surfaces: array<string, SurfaceEntry>}
 */
final class SnapshotManifest
{
    public const PATH = 'release/contract-snapshots.json';

    public const FORMAT = 'vaulter.contract-snapshots';

    /** adapter => the schema versions of its inputs it reads */
    public const ADAPTERS = ['php-surface' => [1], 'identities' => [1], 'schema-ddl' => [1], 'npm-surface' => [1]];

    private const SURFACE_KEYS = ['id', 'adapter', 'schema_version', 'owner', 'release_line', 'inputs', 'required', 'status', 'coverage'];

    /** @return Manifest */
    public static function parse(string $json): array
    {
        $data = self::decode($json, self::PATH);
        self::keys($data, ['format', 'version', 'note', 'release_lines', 'surfaces'], ['format', 'version', 'release_lines', 'surfaces'], self::PATH);
        if ($data['format'] !== self::FORMAT || $data['version'] !== 1) {
            throw new ContractError(self::PATH.': unknown format or version');
        }
        if (!is_array($data['release_lines']) || array_is_list($data['release_lines']) && $data['release_lines'] !== []) {
            throw new ContractError(self::PATH.': release_lines must be a map');
        }
        $lines = [];
        foreach ($data['release_lines'] as $line => $definition) {
            self::keys($definition, ['owners', 'note'], ['owners'], "release line {$line}");
            if (!self::strings($definition['owners']) || $definition['owners'] === []) {
                throw new ContractError("release line {$line}: owners must be a non-empty list of strings");
            }
            $lines[(string) $line] = $definition['owners'];
        }
        if (!is_array($data['surfaces']) || !array_is_list($data['surfaces']) || $data['surfaces'] === []) {
            throw new ContractError(self::PATH.': surfaces must be a non-empty list');
        }

        $surfaces = [];
        foreach ($data['surfaces'] as $i => $surface) {
            self::keys($surface, self::SURFACE_KEYS, ['id', 'adapter', 'schema_version', 'owner', 'release_line', 'inputs', 'required'], "surface #{$i}");
            $id = $surface['id'];
            if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9:._-]*$/', $id) !== 1) {
                throw new ContractError("surface #{$i}: invalid id");
            }
            if (isset($surfaces[$id])) {
                throw new ContractError("duplicate surface id {$id}");
            }
            if (!is_string($surface['adapter']) || !isset(self::ADAPTERS[$surface['adapter']])) {
                throw new ContractError("surface {$id}: unknown adapter");
            }
            if (!in_array($surface['schema_version'], self::ADAPTERS[$surface['adapter']], true)) {
                throw new ContractError("surface {$id}: unknown schema_version for {$surface['adapter']}");
            }
            $owner = $surface['owner'];
            $line = $surface['release_line'];
            $perItem = $owner === '*' && $line === '*';
            if (!$perItem && (!is_string($line) || !isset($lines[$line]) || !in_array($owner, $lines[$line], true))) {
                throw new ContractError("surface {$id}: unknown owner or release line");
            }
            if (!self::strings($surface['inputs']) || $surface['inputs'] === []) {
                throw new ContractError("surface {$id}: inputs must be a non-empty list");
            }
            foreach ($surface['inputs'] as $input) {
                SourceReader::validPath($input, true);
            }
            $status = $surface['status'] ?? 'current';
            if (!is_bool($surface['required']) || !in_array($status, ['current', 'pending'], true) || !is_string($surface['coverage'] ?? '')) {
                throw new ContractError("surface {$id}: invalid required, status or coverage");
            }
            $surfaces[$id] = [
                'id' => $id,
                'adapter' => $surface['adapter'],
                'schema_version' => $surface['schema_version'],
                'owner' => $owner,
                'release_line' => $line,
                'inputs' => $surface['inputs'],
                'required' => $surface['required'],
                'status' => $status,
                'coverage' => $surface['coverage'] ?? '',
            ];
        }
        ksort($surfaces, SORT_STRING);

        return ['release_lines' => $lines, 'surfaces' => $surfaces];
    }

    /**
     * The surfaces to check: every surface of base and head. A base surface missing from head, one whose adapter, owner or
     * release line changed, or one `current` in the base that head turns `pending` (which would stop comparing it and hide a
     * base BREAK) is an error; the inputs are the union of both sides.
     *
     * @param  Manifest                    $base
     * @param  Manifest                    $head
     * @return array<string, SurfaceEntry>
     */
    public static function union(array $base, array $head): array
    {
        $union = $head['surfaces'];
        foreach ($base['surfaces'] as $id => $surface) {
            if (!isset($head['surfaces'][$id])) {
                throw new ContractError("surface {$id} removed from the head manifest");
            }
            foreach (['adapter', 'owner', 'release_line'] as $field) {
                if ($head['surfaces'][$id][$field] !== $surface[$field]) {
                    throw new ContractError("surface {$id}: {$field} changed between base and head");
                }
            }
            if ($surface['status'] === 'current' && $head['surfaces'][$id]['status'] !== 'current') {
                throw new ContractError("surface {$id}: status current in the base cannot become {$head['surfaces'][$id]['status']} in the head (no narrowing of the checked scope)");
            }
            $inputs = array_values(array_unique([...$surface['inputs'], ...$head['surfaces'][$id]['inputs']]));
            sort($inputs, SORT_STRING);
            $union[$id]['inputs'] = $inputs;
            $union[$id]['required'] = $surface['required'] || $head['surfaces'][$id]['required'];
        }
        ksort($union, SORT_STRING);

        return $union;
    }

    /**
     * The bytes of the inputs of one surface on one side. A required input that is missing, or a required glob that
     * matches nothing, is an error.
     *
     * @param  SurfaceEntry          $surface
     * @return array<string, string> path => bytes
     */
    public static function collect(array $surface, SourceReader $reader): array
    {
        $files = [];
        foreach ($surface['inputs'] as $input) {
            $paths = strpbrk($input, '*?') === false ? [$input] : $reader->glob($input);
            if ($paths === [] && $surface['required']) {
                throw new ContractError("surface {$surface['id']}: required glob {$input} matches nothing");
            }
            foreach ($paths as $path) {
                $bytes = $reader->read($path);
                if ($bytes === null) {
                    if ($surface['required']) {
                        throw new ContractError("surface {$surface['id']}: required snapshot {$path} is missing");
                    }

                    continue;
                }
                $files[$path] = $bytes;
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /** @return array<string, mixed> */
    public static function decode(string $json, string $where): array
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ContractError("{$where}: corrupted JSON ({$e->getMessage()})", 0, $e);
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new ContractError("{$where}: a JSON object is required");
        }

        return $data;
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $required
     */
    public static function keys(mixed $data, array $allowed, array $required, string $where): void
    {
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new ContractError("{$where}: an object is required");
        }
        $unknown = array_diff(array_keys($data), $allowed);
        $missing = array_diff($required, array_keys($data));
        if ($unknown !== [] || $missing !== []) {
            throw new ContractError("{$where}: ".($unknown !== [] ? 'unknown field '.implode(', ', $unknown) : 'missing field '.implode(', ', $missing)));
        }
    }

    private static function strings(mixed $list): bool
    {
        return is_array($list) && array_is_list($list) && array_filter($list, static fn (mixed $v): bool => !is_string($v) || $v === '') === [];
    }
}
