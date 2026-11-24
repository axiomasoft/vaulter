<?php

declare(strict_types=1);

namespace Vaulter\Database;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Api;

/**
 * Generates and idempotently applies PostgreSQL Row-Level Security (RLS),
 * fail-closed AS RESTRICTIVE InitPlan policies, security_invoker views,
 * and optional vaulter_migrator / vaulter_runtime roles.
 */
#[Api(since: '1.0')]
final readonly class RlsPolicyBuilder
{
    public const string DEFAULT_SCHEMA = 'public';

    public const string DEFAULT_COLUMN = 'tenant_id';

    public const string DEFAULT_RUNTIME_ROLE = 'vaulter_runtime';

    public const string DEFAULT_MIGRATOR_ROLE = 'vaulter_migrator';

    public function __construct(private VaulterDatabase $database) {}

    /**
     * Build idempotent RLS DDL statements for a single PostgreSQL table.
     *
     * @return list<string>
     */
    public function statementsForTable(
        string $table,
        string $columnType,
        string $schema = self::DEFAULT_SCHEMA,
        string $column = self::DEFAULT_COLUMN,
        string $role = self::DEFAULT_RUNTIME_ROLE,
    ): array {
        $quotedTable = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($table);
        $quotedColumn = $this->quoteIdentifier($column);
        $quotedRole = $this->quoteIdentifier($role);
        $castType = $this->normalizeCastType($columnType);

        $initPlan = sprintf(
            "(SELECT NULLIF(current_setting('vaulter.tenant_id', true), '')::%s)",
            $castType,
        );
        $predicate = sprintf('%s = %s', $quotedColumn, $initPlan);

        return [
            sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $quotedTable),
            sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $quotedTable),
            sprintf('DROP POLICY IF EXISTS "vaulter_rls_allow" ON %s', $quotedTable),
            sprintf('CREATE POLICY "vaulter_rls_allow" ON %s AS PERMISSIVE FOR ALL TO %s USING (true) WITH CHECK (true)', $quotedTable, $quotedRole),
            sprintf('DROP POLICY IF EXISTS "vaulter_rls_tenant_isolation" ON %s', $quotedTable),
            sprintf(
                'CREATE POLICY "vaulter_rls_tenant_isolation" ON %s AS RESTRICTIVE FOR ALL TO %s USING (%s) WITH CHECK (%s)',
                $quotedTable,
                $quotedRole,
                $predicate,
                $predicate,
            ),
        ];
    }

    /** Build idempotent security_invoker DDL for a PostgreSQL view. */
    public function statementForView(string $view, string $schema = self::DEFAULT_SCHEMA): string
    {
        $quotedView = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($view);

        return sprintf('ALTER VIEW %s SET (security_invoker = true)', $quotedView);
    }

    /**
     * Build idempotent role and privilege DDL for vaulter_migrator and vaulter_runtime.
     *
     * @param  list<string> $tables
     * @param  list<string> $views
     * @return list<string>
     */
    public function statementsForRoles(
        array $tables,
        array $views = [],
        string $schema = self::DEFAULT_SCHEMA,
        string $migratorRole = self::DEFAULT_MIGRATOR_ROLE,
        string $runtimeRole = self::DEFAULT_RUNTIME_ROLE,
    ): array {
        $this->assertValidIdentifier($migratorRole);
        $this->assertValidIdentifier($runtimeRole);

        $quotedSchema = $this->quoteIdentifier($schema);
        $quotedRuntime = $this->quoteIdentifier($runtimeRole);
        $quotedMigrator = $this->quoteIdentifier($migratorRole);

        $statements = [
            sprintf(
                "DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '%s') THEN CREATE ROLE %s NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOINHERIT LOGIN; END IF; END \$\$;",
                $runtimeRole,
                $quotedRuntime,
            ),
            sprintf(
                "DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '%s') THEN CREATE ROLE %s NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT LOGIN; END IF; END \$\$;",
                $migratorRole,
                $quotedMigrator,
            ),
            sprintf('GRANT USAGE ON SCHEMA %s TO %s', $quotedSchema, $quotedRuntime),
            sprintf('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA %s TO %s', $quotedSchema, $quotedRuntime),
        ];

        foreach ($tables as $table) {
            $statements[] = sprintf(
                'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE %s.%s TO %s',
                $quotedSchema,
                $this->quoteIdentifier($table),
                $quotedRuntime,
            );
        }

        foreach ($views as $view) {
            $statements[] = sprintf(
                'GRANT SELECT ON TABLE %s.%s TO %s',
                $quotedSchema,
                $this->quoteIdentifier($view),
                $quotedRuntime,
            );
        }

        return $statements;
    }

    /**
     * Discover prefixed PostgreSQL tables carrying `tenant_id` and prefixed views.
     *
     * @return array{tables: list<array{table: string, type: string}>, views: list<string>}
     */
    public function discoverTargets(
        Connection $connection,
        string $prefix,
        string $schema = self::DEFAULT_SCHEMA,
        string $column = self::DEFAULT_COLUMN,
    ): array {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('PostgreSQL RLS target discovery requires a pgsql connection.');
        }

        $this->assertValidIdentifier($schema);
        $this->assertValidIdentifier($column);
        $likePattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%';

        $tableRows = $connection->select(
            "SELECT c.relname AS table_name, pg_catalog.format_type(a.atttypid, a.atttypmod) AS column_type
             FROM pg_catalog.pg_class c
             INNER JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
             INNER JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
             WHERE n.nspname = ?
               AND c.relkind IN ('r', 'p')
               AND c.relname LIKE ? ESCAPE '\\'
               AND a.attname = ?
               AND a.attnum > 0
               AND NOT a.attisdropped
             ORDER BY c.relname",
            [$schema, $likePattern, $column],
        );

        $tables = [];
        foreach ($tableRows as $row) {
            $rowArray = (array) $row;
            $tableName = $rowArray['table_name'] ?? null;
            $columnType = $rowArray['column_type'] ?? null;
            if (is_string($tableName) && is_string($columnType)) {
                $tables[] = ['table' => $tableName, 'type' => $columnType];
            }
        }

        $viewRows = $connection->select(
            "SELECT c.relname AS view_name
             FROM pg_catalog.pg_class c
             INNER JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ?
               AND c.relkind = 'v'
               AND c.relname LIKE ? ESCAPE '\\'
             ORDER BY c.relname",
            [$schema, $likePattern],
        );

        $views = [];
        foreach ($viewRows as $row) {
            $rowArray = (array) $row;
            $viewName = $rowArray['view_name'] ?? null;
            if (is_string($viewName)) {
                $views[] = $viewName;
            }
        }

        return ['tables' => $tables, 'views' => $views];
    }

    /**
     * Build the full idempotent DDL plan for the discovered targets.
     *
     * @return array{tables: list<string>, views: list<string>, statements: list<string>}
     */
    public function buildPlan(
        string $prefix,
        string $schema = self::DEFAULT_SCHEMA,
        string $runtimeRole = self::DEFAULT_RUNTIME_ROLE,
        string $migratorRole = self::DEFAULT_MIGRATOR_ROLE,
        bool $withRoles = false,
    ): array {
        $targets = $this->discoverTargets($this->database->connection(), $prefix, $schema);
        $tableNames = array_map(static fn (array $item): string => $item['table'], $targets['tables']);
        $views = $targets['views'];

        $statements = [];
        if ($withRoles) {
            array_push($statements, ...$this->statementsForRoles(
                tables: $tableNames,
                views: $views,
                schema: $schema,
                migratorRole: $migratorRole,
                runtimeRole: $runtimeRole,
            ));
        }

        foreach ($targets['tables'] as $target) {
            array_push($statements, ...$this->statementsForTable(
                table: $target['table'],
                columnType: $target['type'],
                schema: $schema,
                role: $runtimeRole,
            ));
        }

        foreach ($views as $view) {
            $statements[] = $this->statementForView($view, $schema);
        }

        return ['tables' => $tableNames, 'views' => $views, 'statements' => $statements];
    }

    /**
     * Execute the idempotent RLS DDL plan inside a VaulterDatabase transaction.
     *
     * @return array{tables: list<string>, views: list<string>, statements: list<string>}
     */
    public function install(
        string $prefix,
        string $schema = self::DEFAULT_SCHEMA,
        string $runtimeRole = self::DEFAULT_RUNTIME_ROLE,
        string $migratorRole = self::DEFAULT_MIGRATOR_ROLE,
        bool $withRoles = false,
    ): array {
        $plan = $this->buildPlan(
            prefix: $prefix,
            schema: $schema,
            runtimeRole: $runtimeRole,
            migratorRole: $migratorRole,
            withRoles: $withRoles,
        );

        if ($plan['statements'] === []) {
            return $plan;
        }

        $this->database->transaction(function (Connection $connection) use ($plan): void {
            $pdo = $connection->getPdo();
            foreach ($plan['statements'] as $statement) {
                $pdo->exec($statement);
            }
        });

        return $plan;
    }

    private function assertValidIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid PostgreSQL identifier [%s].', $identifier));
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        $this->assertValidIdentifier($identifier);

        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function normalizeCastType(string $columnType): string
    {
        $normalized = strtolower(trim($columnType));

        if (in_array($normalized, ['bigint', 'integer', 'smallint', 'uuid', 'text'], true)) {
            return $normalized;
        }

        if (str_starts_with($normalized, 'character varying') || str_starts_with($normalized, 'varchar')) {
            return 'character varying';
        }

        if (preg_match('/^character\(\d+\)$/', $normalized) === 1 || $normalized === 'bpchar' || $normalized === 'character') {
            return $normalized;
        }

        throw new InvalidArgumentException(sprintf('Unsupported tenant_id PostgreSQL type [%s] for RLS cast.', $columnType));
    }
}
