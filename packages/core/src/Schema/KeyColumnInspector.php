<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\ValueObjects\KeyColumnExpectation;
use Vaulter\Schema\ValueObjects\KeyColumnMismatch;

/** @internal */
final class KeyColumnInspector
{
    public function __construct(
        private readonly VaulterDatabase $db,
        private readonly DatabaseConfig $database,
        private readonly IdsConfig $ids,
    ) {}

    /** @return list<KeyColumnExpectation> */
    public function expected(): array
    {
        $hostKeys = [
            ['drives', 'tenant_id'], ['drives', 'owner_id'], ['drives', 'created_by'],
            ['nodes', 'tenant_id'], ['nodes', 'trashed_by'], ['nodes', 'created_by'], ['nodes', 'updated_by'], ['nodes', 'content_modified_by'],
            ['blobs', 'tenant_id'],
            ['files', 'tenant_id'], ['files', 'created_by'],
            ['file_revisions', 'tenant_id'], ['file_revisions', 'created_by'],
            ['node_grants', 'tenant_id'], ['node_grants', 'subject_id'], ['node_grants', 'granted_by'],
            ['node_shares', 'tenant_id'], ['node_shares', 'granted_by'],
            ['node_comments', 'tenant_id'], ['node_comments', 'author_id'], ['node_comments', 'resolved_by'],
            ['node_user_states', 'tenant_id'], ['node_user_states', 'actor_id'],
            ['documents', 'tenant_id'], ['documents', 'created_by'], ['documents', 'updated_by'],
            ['document_revisions', 'tenant_id'], ['document_revisions', 'created_by'],
            ['upload_sessions', 'tenant_id'], ['upload_sessions', 'created_by'],
            ['activity', 'tenant_id'], ['activity', 'actor_id'],
        ];
        $references = [
            ['nodes', 'drive_id'],
            ['node_user_states', 'node_id'],
            ['blobs', 'drive_id'],
            ['file_revision_blobs', 'file_revision_id'],
            ['upload_sessions', 'drive_id'],
            ['upload_sessions', 'target_node_id'],
            ['upload_sessions', 'result_node_id'],
            ['upload_sessions', 'result_revision_id'],
            ['activity', 'drive_id'],
            ['outbox', 'drive_id'],
        ];
        $strings = [
            ['drives', 'profile', 64],
            ['outbox', 'profile', 64],
            ['activity', 'event_id', 26],
            ['outbox', 'aggregate_id', 64],
        ];

        return [
            ...array_map(fn (array $column): KeyColumnExpectation => new KeyColumnExpectation($column[0], $column[1], 'HK'), $hostKeys),
            ...array_map(fn (array $column): KeyColumnExpectation => new KeyColumnExpectation($column[0], $column[1], 'REF'), $references),
            ...array_map(fn (array $column): KeyColumnExpectation => new KeyColumnExpectation($column[0], $column[1], 'STRING', $column[2]), $strings),
        ];
    }

    /** @return list<KeyColumnMismatch> */
    public function mismatches(): array
    {
        $schema = $this->db->connection()->getSchemaBuilder();
        $columnsByTable = [];
        $mismatches = [];

        foreach ($this->expected() as $expectation) {
            $table = $this->database->table($expectation->table);
            if (!$schema->hasTable($table)) {
                $mismatches[] = new KeyColumnMismatch($expectation, 'missing');

                continue;
            }

            $columnsByTable[$table] ??= array_column($schema->getColumns($table), null, 'name');
            $column = $columnsByTable[$table][$expectation->column] ?? null;
            if (!is_array($column)) {
                $mismatches[] = new KeyColumnMismatch($expectation, 'missing');

                continue;
            }

            $actual = strtolower($column['type']);
            if (!$this->typeMatches($expectation, $column)) {
                $mismatches[] = new KeyColumnMismatch($expectation, 'type', $actual);

                continue;
            }

            if ($expectation->length !== null && $this->length($column) !== $expectation->length) {
                $mismatches[] = new KeyColumnMismatch($expectation, 'length', $actual);
            }
        }

        return $mismatches;
    }

    /** @param array{name: string, type: string, type_name: string} $column */
    private function typeMatches(KeyColumnExpectation $expectation, array $column): bool
    {
        $typeName = strtolower($column['type_name']);
        $type = strtolower($column['type']);

        if ($expectation->kind === 'STRING') {
            return in_array($typeName, ['varchar', 'character varying'], true);
        }

        $expected = $expectation->kind === 'HK'
            ? HostKeyColumns::sqlType($this->db->driver())
            : match ($this->ids->strategy()) {
                'bigint' => $this->db->driver() === 'pgsql' ? 'bigint' : 'bigint unsigned',
                'ulid' => 'char(26)',
                'uuid7' => $this->db->driver() === 'pgsql' ? 'uuid' : 'char(36)',
                default => '',
            };

        return match (true) {
            str_starts_with($expected, 'varchar') => in_array($typeName, ['varchar', 'character varying'], true),
            str_starts_with($expected, 'char') => in_array($typeName, ['char', 'bpchar', 'character'], true),
            $expected === 'uuid' => $typeName === 'uuid',
            str_starts_with($expected, 'bigint') => in_array($typeName, ['bigint', 'int8'], true)
                && (!str_contains($expected, 'unsigned') || str_contains($type, 'unsigned')),
            default => false,
        };
    }

    /** @param array{type: string} $column */
    private function length(array $column): ?int
    {
        $type = strtolower($column['type']);

        return preg_match('/\((\d+)\)/', $type, $matches) === 1 ? (int) $matches[1] : null;
    }
}
