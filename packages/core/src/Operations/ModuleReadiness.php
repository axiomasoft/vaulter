<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Modules\ModuleNotReadyException;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleId;
use Vaulter\Schema\ModuleSchemas;
use Vaulter\Support\Identity;

/**
 * Whether a module can run its writes: it is active and the tables it owns (and the kernel's) exist.
 * Lazy: nothing is queried at boot; the first write of the process reads the table list once and a
 * ready module is remembered for the rest of the process. A module found not ready is checked again
 * on its next write, so a migration run meanwhile is picked up.
 */
#[Internal]
final class ModuleReadiness
{
    /** @var array<string, true> */
    private array $ready = [];

    /** @var array<string, true>|null */
    private ?array $tables = null;

    public function __construct(
        private readonly Kernel $kernel,
        private readonly VaulterDatabase $database,
        private readonly DatabaseConfig $config,
        private readonly ModuleSchemas $schemas,
    ) {}

    /** @throws ModuleNotReadyException */
    public function assertReady(ModuleId $module): void
    {
        if (isset($this->ready[$module->value])) {
            return;
        }
        if (!$this->kernel->isActive($module->value)) {
            throw ModuleNotReadyException::inactive($module->value);
        }

        $tables = $this->tables ??= $this->existingTables();
        $missing = [];
        foreach (array_unique([...$this->schemas->entitiesOf('core'), ...$this->schemas->entitiesOf($module->value)]) as $entity) {
            $table = $this->config->table($entity);
            if (!isset($tables[$table])) {
                $missing[] = $table;
            }
        }
        if ($missing !== []) {
            $this->tables = null;

            throw ModuleNotReadyException::missingTables($module->value, $missing);
        }

        $this->ready[$module->value] = true;
    }

    /**
     * Which of the Vaulter tables exist, in one catalog query.
     *
     * @return array<string, true> table name (without the connection prefix) => true
     */
    private function existingTables(): array
    {
        $connection = $this->database->connection();
        $prefix = $connection->getTablePrefix();
        $names = [];
        foreach ($this->schemas->allEntities() as $entity) {
            $names[$prefix.$this->config->table($entity)] = $this->config->table($entity);
        }
        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $sql = match ($connection->getDriverName()) {
            'sqlite' => "select name from sqlite_master where type = 'table' and name in ({$placeholders})",
            'pgsql' => "select table_name as name from information_schema.tables where table_schema = any (current_schemas(false)) and table_name in ({$placeholders})",
            default => "select table_name as name from information_schema.tables where table_schema = database() and table_name in ({$placeholders})",
        };

        $existing = [];
        foreach ($connection->select($sql, array_keys($names)) as $row) {
            $name = Identity::stringify(((array) $row)['name'] ?? null);
            if (isset($names[$name])) {
                $existing[$names[$name]] = true;
            }
        }

        return $existing;
    }
}
