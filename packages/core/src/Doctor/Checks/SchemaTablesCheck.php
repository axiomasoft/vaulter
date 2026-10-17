<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Schema\ModuleSchemas;

#[Internal]
final readonly class SchemaTablesCheck implements DoctorCheck
{
    public function __construct(
        private VaulterDatabase $database,
        private DatabaseConfig $tables,
        private Container $container,
        private ModuleSchemas $schemas,
    ) {}

    public function key(): string
    {
        return 'core.schema.tables';
    }

    public function title(): string
    {
        return 'Schema tables and migrations';
    }

    public function run(DoctorContext $context): array
    {
        // hasTable() asks about the connection's own database: getTables() without a schema lists every database of a MySQL
        // server, so a table of a neighbouring database would hide a missing one.
        $builder = $this->database->connection()->getSchemaBuilder();
        $missing = [];
        $entities = $this->schemas->allEntities();
        foreach ($entities as $entity) {
            if (!$builder->hasTable($this->tables->table($entity))) {
                $missing[] = $this->tables->table($entity);
            }
        }

        $findings = [];
        $findings[] = $missing === []
            ? DoctorFinding::ok(code: 'core.schema.tables_present', message: 'Every Vaulter table exists.', evidence: ['tables' => count($entities)])
            : DoctorFinding::error(code: 'core.schema.tables_missing', message: count($missing).' Vaulter table(s) are missing.', hint: 'run php artisan migrate', evidence: ['missing' => implode(', ', array_slice($missing, 0, 10)), 'count' => count($missing)]);

        $pending = $this->pendingMigrations();
        $findings[] = $pending === null
            ? DoctorFinding::error(code: 'core.schema.migrations_table_missing', message: 'The migrations table does not exist.', hint: 'run php artisan migrate')
            : ($pending === []
                ? DoctorFinding::ok(code: 'core.schema.migrations_applied', message: 'Every Vaulter migration is applied.')
                : DoctorFinding::error(code: 'core.schema.migrations_pending', message: count($pending).' Vaulter migration(s) are pending.', hint: 'run php artisan migrate', evidence: ['first' => $pending[0], 'count' => count($pending)]));

        return $findings;
    }

    /** @return list<string>|null null when the migrations table is missing */
    private function pendingMigrations(): ?array
    {
        /** @var MigrationRepositoryInterface $migrations */
        $migrations = $this->container->make('migration.repository');
        if (!$migrations->repositoryExists()) {
            return null;
        }

        // A published migration gets a new timestamp prefix; its name after the prefix is what identifies it.
        $applied = array_map(self::bareName(...), $migrations->getRan());
        $files = [];
        foreach ($this->schemas->migrationPaths() as $directory) {
            array_push($files, ...(glob(rtrim($directory, '/').'/*.php') ?: []));
        }
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!in_array(self::bareName($name), $applied, true)) {
                $pending[] = $name;
            }
        }
        sort($pending);

        return $pending;
    }

    private static function bareName(string $name): string
    {
        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name) ?? $name;
    }
}
