<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\RlsPolicyBuilder;
use Vaulter\Database\VaulterDatabase;

/**
 * Idempotently installs PostgreSQL Row-Level Security (ENABLE + FORCE + AS RESTRICTIVE InitPlan),
 * view security_invoker=true, and optional vaulter_migrator / vaulter_runtime roles.
 */
#[Internal]
final class InstallRlsCommand extends Command
{
    protected $signature = 'vaulter:rls:install
        {--schema=public : PostgreSQL schema to configure}
        {--prefix= : Table and view name prefix (defaults to vaulter.database.table_prefix)}
        {--role=vaulter_runtime : PostgreSQL runtime role for RLS policies}
        {--migrator-role=vaulter_migrator : PostgreSQL migrator role for optional role DDL}
        {--roles : Create/update vaulter_migrator and vaulter_runtime roles and grants}
        {--dry-run : Output DDL statements without executing them}';

    protected $description = 'Install PostgreSQL Row-Level Security policies, security_invoker views and roles for Vaulter tables.';

    public function handle(VaulterDatabase $database, DatabaseOptions $databaseOptions, RlsPolicyBuilder $builder): int
    {
        $connection = $database->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            $this->components->error('PostgreSQL RLS installation requires a pgsql connection.');

            return self::FAILURE;
        }

        $schema = $this->stringOption('schema', RlsPolicyBuilder::DEFAULT_SCHEMA);
        $prefix = $this->stringOption('prefix', $databaseOptions->tablePrefix);
        $runtimeRole = $this->stringOption('role', RlsPolicyBuilder::DEFAULT_RUNTIME_ROLE);
        $migratorRole = $this->stringOption('migrator-role', RlsPolicyBuilder::DEFAULT_MIGRATOR_ROLE);
        $withRoles = $this->option('roles');

        if ($this->option('dry-run')) {
            $plan = $builder->buildPlan(
                prefix: $prefix,
                schema: $schema,
                runtimeRole: $runtimeRole,
                migratorRole: $migratorRole,
                withRoles: $withRoles,
            );
            foreach ($plan['statements'] as $statement) {
                $this->line($statement.';');
            }

            return self::SUCCESS;
        }

        $plan = $builder->install(
            prefix: $prefix,
            schema: $schema,
            runtimeRole: $runtimeRole,
            migratorRole: $migratorRole,
            withRoles: $withRoles,
        );

        $this->components->info(sprintf(
            'PostgreSQL RLS configured: %d table(s), %d view(s), %d statement(s).',
            count($plan['tables']),
            count($plan['views']),
            count($plan['statements']),
        ));

        return self::SUCCESS;
    }

    private function stringOption(string $name, string $default): string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
