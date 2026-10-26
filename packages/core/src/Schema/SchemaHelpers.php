<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Attributes\Internal;
use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Contracts\IdStrategy;

/**
 * The generic column, key and connection helpers a module's schema class builds its tables from: the Vaulter connection,
 * the id strategy, the host-key and tenant columns, counters, engine-specific column types and the composite head
 * foreign key. It knows no table of any module.
 */
#[Spi(SpiKind::Call)]
final class SchemaHelpers
{
    #[Internal]
    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly IdStrategyResolver $resolver,
    ) {}

    /**
     * The Schema builder for the connection Vaulter's tables live on
     * (`DatabaseConfig::connection()`) — null defers to the host's default
     * connection exactly as every call site did before this key existed.
     */
    public function schemaConnection(): Builder
    {
        return Schema::connection($this->config->connection());
    }

    /**
     * The raw-statement connection counterpart of {@see schemaConnection()}, for
     * the driver-aware `DB::statement(...)` calls (partial/gin indexes, MySQL
     * generated-column fallbacks) that Facade\Schema has no builder method for.
     */
    public function dbConnection(): Connection
    {
        return DB::connection($this->config->connection());
    }

    public function ids(): IdStrategy
    {
        return $this->resolver->resolve();
    }

    public function table(string $entity): string
    {
        return $this->config->table($entity);
    }

    /**
     * The default-panel discriminator column. Only emitted on the panel roots
     * (storages + nodes); other entities resolve their panel via their parent FK.
     */
    public function panelDiscriminator(Blueprint $table): void
    {
        $table->string(column: 'profile', length: 64)->default(value: 'default')->index();
    }

    /**
     * Tenant keys belong to the host. The column always exists for CAS scope indexes.
     */
    public function tenant(Blueprint $table): void
    {
        HostKeyColumns::add(table: $table, column: 'tenant_id', nullable: true, index: true);
    }

    /**
     * The activity ip column. A native inet on Postgres (efficient,
     * type-checked); a portable varchar(45) (max IPv6 length) everywhere else. The
     * Activity model casts it to a plain string either way.
     */
    public function ipColumn(Blueprint $table): void
    {
        if ($this->schemaConnection()->getConnection()->getDriverName() === 'pgsql') {
            $table->ipAddress('ip')->nullable();

            return;
        }

        $table->string('ip', 45)->nullable();
    }

    /**
     * A nullable binary column (collab_state). longBlob on MySQL/MariaDB (the
     * portability note — Yjs state can outgrow a plain blob); a portable
     * binary() (bytea on Postgres) everywhere else.
     */
    public function binaryColumn(Blueprint $table, string $column): void
    {
        $driver = $this->schemaConnection()->getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $table->longText($column)->nullable()->charset('binary');

            return;
        }

        $table->binary($column)->nullable();
    }

    /**
     * A signed BIGINT counter (0..2^63-1) with the same contract as
     * v_nodes.sequence: the CHECK rejects negatives on every driver, and SQLite
     * additionally rejects REAL values its overflow promotion would produce.
     */
    public function counterColumn(Blueprint $table, string $column): void
    {
        $type = $this->dbConnection()->getDriverName() === 'sqlite' ? "typeof({$column}) = 'integer' AND " : '';
        $table->rawColumn($column, "BIGINT CHECK({$type}{$column} >= 0)")->default(0);
    }

    /** name_key compares byte for byte; MySQL needs a binary collation on the column itself. */
    public function nameKeyColumn(Blueprint $table): void
    {
        $column = $table->string('name_key', 1024);
        if (in_array($this->dbConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $column->charset('utf8mb4')->collation('utf8mb4_bin');
        }
    }

    /**
     * The composite head FK (current_revision_id, id) → revisions(id, <owner>): a
     * head can only name a revision of the same aggregate, and RESTRICT keeps
     * pruning from deleting it. SQLite cannot add a constraint to an existing
     * table, so there the Actions (and the head integrity tests) hold the
     * invariant alone.
     */
    public function addHeadForeignKey(string $aggregate, string $revisions, string $owner): void
    {
        $connection = $this->dbConnection();
        $table = $this->table($aggregate);
        $name = $table.'_head_fk';

        if ($connection->getDriverName() === 'sqlite'
            || !$this->schemaConnection()->hasColumn($table, 'current_revision_id')
            || $this->hasForeignKey($table, $name)) {
            return;
        }

        $grammar = $connection->getQueryGrammar();
        $connection->statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s, %s) REFERENCES %s (%s, %s) ON DELETE RESTRICT',
            $grammar->wrapTable($table),
            $grammar->wrap($name),
            $grammar->wrap('current_revision_id'),
            $grammar->wrap('id'),
            $grammar->wrapTable($this->table($revisions)),
            $grammar->wrap('id'),
            $grammar->wrap($owner),
        ));
    }

    public function dropHeadForeignKey(string $aggregate): void
    {
        $table = $this->table($aggregate);
        $name = $table.'_head_fk';

        if ($this->schemaConnection()->hasTable($table) && $this->hasForeignKey($table, $name)) {
            $this->schemaConnection()->table($table, static fn (Blueprint $blueprint) => $blueprint->dropForeign($name));
        }
    }

    public function hasForeignKey(string $table, string $name): bool
    {
        foreach ($this->schemaConnection()->getForeignKeys($table) as $key) {
            if ($key['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * The mysql driver only ever installs CHECKs it can rely on: a server that parses but ignores
     * them (MariaDB behind the mysql driver, MySQL before 8.0.16) or clamps numeric overflow
     * (no STRICT sql_mode) fails closed instead of installing a constraint that never fires.
     */
    public function assertEnforcedMysqlChecks(): void
    {
        $db = $this->dbConnection();
        if ($db->getDriverName() !== 'mysql') {
            return;
        }
        $engine = $db->selectOne('SELECT VERSION() AS v, @@SESSION.sql_mode AS m');
        if (!$engine instanceof \stdClass || !is_string($engine->v ?? null) || !is_string($engine->m ?? null)) {
            throw new \RuntimeException('Cannot prove enforced MySQL constraints.');
        }
        if (str_contains($engine->v, 'MariaDB') || version_compare($engine->v, '8.0.16', '<') || !str_contains($engine->m, 'STRICT_')) {
            throw new \RuntimeException('Schema requires enforced MySQL CHECKs and strict numeric overflow.');
        }
    }
}
