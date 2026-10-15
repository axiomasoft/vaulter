<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Operations\ModuleReadiness;

final readonly class SchemaTablesCheck implements DoctorCheck
{
    /** The entity keys of the tables core's migrations create, drive and documents data included. */
    private const array ENTITIES = [
        'drives', 'nodes', 'blobs', 'files', 'file_revisions', 'file_revision_blobs',
        'scan_policy_states', 'scan_obligations', 'scan_backfill_runs', 'scan_requests', 'scan_evidence',
        'node_grants', 'node_shares', 'node_comments', 'node_user_states',
        'documents', 'document_revisions', 'upload_sessions', 'upload_parts',
        'activity', 'outbox', 'webhook_endpoints', 'webhook_deliveries', 'drive_change_heads', 'drive_changes',
        'outbox_consumers', 'outbox_deliveries', 'idempotency_keys',
    ];

    /**
     * The same tables by the module that owns them (core still ships every migration until the
     * packages take theirs over): the source of {@see ModuleReadiness}.
     *
     * @var array<string, list<string>> module id => entity keys
     */
    public const array OWNED_ENTITIES = [
        'core' => ['outbox', 'outbox_consumers', 'outbox_deliveries', 'idempotency_keys'],
        'media' => ['blobs'],
        'drive' => [
            'drives', 'nodes', 'files', 'file_revisions', 'file_revision_blobs',
            'scan_policy_states', 'scan_obligations', 'scan_backfill_runs', 'scan_requests', 'scan_evidence',
            'node_grants', 'node_shares', 'node_user_states', 'upload_sessions', 'upload_parts',
            'activity', 'drive_change_heads', 'drive_changes',
        ],
        'documents' => ['documents', 'document_revisions', 'node_comments'],
        'webhooks' => ['webhook_endpoints', 'webhook_deliveries'],
    ];

    public function __construct(
        private VaulterDatabase $database,
        private DatabaseConfig $tables,
        private Container $container,
    ) {}

    /** @return list<string> the entity keys of the tables `$module` owns; empty for a module without tables */
    public static function ownedBy(string $module): array
    {
        return self::OWNED_ENTITIES[$module] ?? [];
    }

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
        $existing = array_column($this->database->connection()->getSchemaBuilder()->getTables(), 'name');
        $missing = [];
        foreach (self::ENTITIES as $entity) {
            if (!in_array($this->tables->table($entity), $existing, true)) {
                $missing[] = $this->tables->table($entity);
            }
        }

        $findings = [];
        $findings[] = $missing === []
            ? DoctorFinding::ok(code: 'core.schema.tables_present', message: 'Every Vaulter table exists.', evidence: ['tables' => count(self::ENTITIES)])
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
        $files = glob(dirname(__DIR__, 3).'/database/migrations/*.php') ?: [];
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
