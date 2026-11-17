<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Vaulter\Configuration\IdsOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\KeyColumnInspector;

#[Internal]
final readonly class SchemaKeyTypesCheck implements DoctorCheck
{
    public function __construct(private VaulterDatabase $database, private KeyColumnInspector $inspector, private IdsOptions $ids) {}

    public function key(): string
    {
        return 'core.schema.key_types';
    }

    public function title(): string
    {
        return 'Key column types';
    }

    public function run(DoctorContext $context): array
    {
        $driver = $this->database->driver();
        if (!in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            // Column types are only comparable where the schema keeps them; core.database.connection reports the driver.
            return [DoctorFinding::ok(code: 'core.schema.key_types_skipped', message: 'Key types are checked on PostgreSQL, MySQL and MariaDB only.', evidence: ['skipped' => true, 'driver' => $driver])];
        }

        $mismatches = $this->inspector->mismatches();
        if ($mismatches === []) {
            return [DoctorFinding::ok(code: 'core.schema.key_types_match', message: 'Key columns match ids.strategy and ids.host_keys.', evidence: ['ids_strategy' => $this->ids->strategy, 'ids_host_keys' => $this->ids->hostKeys])];
        }

        $evidence = ['count' => count($mismatches), 'ids_strategy' => $this->ids->strategy, 'ids_host_keys' => $this->ids->hostKeys];
        foreach (array_slice($mismatches, 0, 10) as $mismatch) {
            $evidence[$mismatch->expectation->table.'.'.$mismatch->expectation->column] = $mismatch->reason.($mismatch->actual === null ? '' : ' ('.$mismatch->actual.')');
        }

        return [DoctorFinding::error(
            code: 'core.schema.key_types_mismatch',
            message: count($mismatches).' key column(s) do not match ids.strategy / ids.host_keys.',
            remediation: 'restore the ids settings the schema was created with, or migrate the columns',
            evidence: $evidence,
        )];
    }
}
