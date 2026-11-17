<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Configuration\IdsOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\ValueObjects\KeyColumnExpectation;
use Vaulter\Schema\ValueObjects\KeyColumnMismatch;

#[Internal]
final class KeyColumnInspector
{
    public function __construct(
        private readonly VaulterDatabase $db,
        private readonly DatabaseOptions $database,
        private readonly IdsOptions $ids,
        private readonly ModuleSchemas $schemas,
    ) {}

    /** @return list<KeyColumnExpectation> the key columns every offered module declares for its own tables */
    public function expected(): array
    {
        return array_merge([], ...array_values(array_map(
            static fn (ModuleSchema $schema): array => $schema->keyColumns(),
            $this->schemas->all(),
        )));
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
            : match ($this->ids->strategy) {
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
