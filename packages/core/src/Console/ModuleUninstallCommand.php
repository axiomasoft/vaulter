<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Kernel;
use Vaulter\Modules\HoldsReferences;
use Vaulter\Modules\ModuleReferences;
use Vaulter\Modules\ModuleState;
use Vaulter\Modules\VaulterModule;

/**
 * Plans, and on `--force` performs, the removal of a module's schema (D36): its migrations and tables, the installed
 * modules that require it, the foreign keys other owners hold on its tables, and the references (media pins, D23)
 * kept on its data or by it. A dry run by default. `--force` rolls back the migrations of the module's own schema paths
 * (newest first) — only while its tables hold no row and it keeps no reference; `--purge` (with `--force`) first
 * deletes its rows and releases its own references in one transaction. It never drops, deletes or releases anything of
 * another owner, and disabling a module never calls it.
 *
 * Exit codes: 0 planned or done, 1 refused (nothing changed), 2 usage.
 */
#[Internal]
final class ModuleUninstallCommand extends Command
{
    protected $signature = 'vaulter:module:uninstall
        {module : The module id}
        {--force : Roll back the module\'s migrations}
        {--purge : Also delete the module\'s rows and its own media pins}';

    protected $description = 'Plan (dry run) or perform the removal of a Vaulter module\'s schema';

    public function handle(Kernel $kernel, DatabaseConfig $config, VaulterDatabase $database, Migrator $migrator, Filesystem $files): int
    {
        $force = $this->option('force') === true;
        $purge = $this->option('purge') === true;
        if ($purge && !$force) {
            $this->components->error('--purge deletes rows only together with --force.');

            return self::INVALID;
        }
        if (!$kernel->isValid()) {
            $this->components->error('The module graph is invalid; run vaulter:modules for the reasons.');

            return self::FAILURE;
        }
        $id = (string) $this->argument('module');
        $module = $kernel->compiled()->module($id);
        if ($module === null) {
            $this->components->error('No installed module is called "'.$id.'".');

            return self::INVALID;
        }

        $migrations = self::migrations($module, $migrator);
        $tables = array_map(static fn (string $entity): string => $config->table($entity), self::entities(array_keys($migrations)));
        $dependents = self::dependents($id, $kernel->modules());
        $connection = $database->connection();
        $incoming = self::incomingForeignKeys($tables, $connection);
        $rows = self::rows($tables, $connection);
        [$holders, $unreadable] = $this->holders($id, $kernel->modules());
        $kept = $keptOn = [];
        foreach ($holders as $holder => $references) {
            try {
                foreach ($references->keptBy($id) as $kind => $count) {
                    $kept[$holder.': '.$kind] = $count;
                }
                if ($holder === $id) {
                    $keptOn = $references->keptOn($id);
                }
            } catch (Throwable $e) {
                $unreadable[$holder] = $e->getMessage();
            }
        }

        $refusals = [];
        if ($id === 'core') {
            $refusals[] = 'the core module cannot be uninstalled';
        }
        foreach ($dependents as $dependent) {
            $refusals[] = sprintf('module "%s" requires it', $dependent);
        }
        foreach ($incoming ?? [] as $fk) {
            $refusals[] = sprintf('%s references %s', $fk['table'], $fk['references']);
        }
        foreach ($keptOn as $kind => $count) {
            $refusals[] = sprintf('%d reference(s) of "%s" are kept on its data; only the purge of their owner releases them', $count, $kind);
        }

        $this->components->twoColumnDetail('Module', $id.($module->active ? ' (active)' : ' (inactive)'));
        $this->components->twoColumnDetail('Migrations', $migrations === [] ? 'none of its own' : (string) count($migrations));
        foreach (array_keys($migrations) as $migration) {
            $this->line('  '.OutputFormatter::escape($migration));
        }
        $this->components->twoColumnDetail('Tables', $tables === [] ? 'none' : implode(', ', $tables));
        $this->components->twoColumnDetail('Rows', $rows === null ? 'unknown (no database connection)' : (string) array_sum($rows));
        $this->components->twoColumnDetail('Required by', $dependents === [] ? 'no installed module' : implode(', ', $dependents));
        $this->components->twoColumnDetail('Incoming foreign keys', $incoming === null ? 'unknown (no database connection)' : ($incoming === [] ? 'none' : (string) count($incoming)));
        $this->components->twoColumnDetail('References it keeps', $kept === [] ? 'none' : self::describe($kept));
        $this->components->twoColumnDetail('References kept on it', $keptOn === [] ? 'none' : self::describe($keptOn));
        foreach ($unreadable as $holder => $reason) {
            $this->components->twoColumnDetail('References in '.$holder, 'unknown ('.OutputFormatter::escape($reason).')');
        }
        foreach ($refusals as $refusal) {
            $this->line('  <fg=red>refused:</> '.OutputFormatter::escape($refusal));
        }

        if (!$force) {
            $this->components->info('Dry run: nothing was changed.');

            return $refusals === [] ? self::SUCCESS : self::FAILURE;
        }

        // What only an execution needs: the facts it could not read, and the consent of --purge to lose data.
        $blocked = [];
        if ($incoming === null || $rows === null) {
            $blocked[] = 'the database cannot be read';
        }
        foreach ($unreadable as $holder => $reason) {
            $blocked[] = sprintf('the references in module "%s" cannot be read (%s)', $holder, $reason);
        }
        if (!$purge && array_sum($rows ?? []) > 0) {
            $blocked[] = 'its tables hold rows; --purge deletes them';
        }
        if (!$purge && $kept !== []) {
            $blocked[] = 'it keeps references; --purge releases them';
        }
        foreach ($blocked as $refusal) {
            $this->line('  <fg=red>refused:</> '.OutputFormatter::escape($refusal));
        }
        if ($refusals !== [] || $blocked !== []) {
            $this->components->error('The module was not uninstalled: nothing was changed.');

            return self::FAILURE;
        }

        if ($purge) {
            [$deleted, $released] = $database->transaction(static function () use ($holders, $id, $tables, $rows, $connection): array {
                $released = 0;
                foreach ($holders as $references) {
                    $released += $references->releaseKeptBy($id);
                }
                $deleted = 0;
                // A table after the ones it references, so the reverse order deletes the referencing rows first.
                foreach (array_reverse($tables) as $table) {
                    if (array_key_exists($table, $rows ?? [])) {
                        $deleted += $connection->table($table)->delete();
                    }
                }

                return [$deleted, $released];
            });
            $this->components->twoColumnDetail('Purged', sprintf('%d row(s), %d reference(s)', $deleted, $released));
        }

        $rolledBack = self::rollBack($migrations, $migrator, $files);
        foreach ($rolledBack as $migration) {
            $this->components->twoColumnDetail(OutputFormatter::escape($migration), '<fg=green;options=bold>ROLLED BACK</>');
        }
        $this->components->info($rolledBack === []
            ? 'Nothing to roll back: the schema of "'.$id.'" is not installed.'
            : 'The schema of "'.$id.'" is removed. Remove its package from Composer, or `migrate` installs it again.');

        return self::SUCCESS;
    }

    /** @return array<string, string> the migration files of the module's schema paths (name => real path), sorted by name */
    private static function migrations(ModuleState $module, Migrator $migrator): array
    {
        $paths = [];
        foreach ($module->schemaPaths as $path) {
            $real = realpath($path);
            if ($real !== false) {
                $paths[] = $real;
            }
        }
        /** @var array<string, string> $files */
        $files = $paths === [] ? [] : $migrator->getMigrationFiles($paths);
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * The entities the create-migrations create (`…_create_v_<entity>_table`), in the order of the migrations.
     *
     * @param  list<string> $migrations
     * @return list<string>
     */
    private static function entities(array $migrations): array
    {
        $entities = [];
        foreach ($migrations as $migration) {
            if (preg_match('/_create_v_([a-z0-9_]+)_table$/', $migration, $m) === 1) {
                $entities[] = $m[1];
            }
        }

        return $entities;
    }

    /**
     * The offered modules whose manifest requires `$id`.
     *
     * @param  list<ModuleState> $modules
     * @return list<string>
     */
    private static function dependents(string $id, array $modules): array
    {
        $dependents = [];
        foreach ($modules as $module) {
            /** @var VaulterModule $manifest */
            $manifest = new ($module->manifest);
            if (array_key_exists($id, $manifest->requires())) {
                $dependents[] = $module->id->value;
            }
        }
        sort($dependents, SORT_STRING);

        return $dependents;
    }

    /**
     * The holders of references `$id` may keep or that are kept on its data: the module itself and the modules it
     * requires, directly or not (a reference, like a foreign key, points along a Composer dependency, D6).
     *
     * @param  list<ModuleState>                                                   $modules
     * @return array{0: array<string, ModuleReferences>, 1: array<string, string>} holder id => its references; holder id => why it cannot be read
     */
    private function holders(string $id, array $modules): array
    {
        $manifests = [];
        foreach ($modules as $module) {
            $manifests[$module->id->value] = $module->manifest;
        }
        $closure = [];
        $visit = static function (string $module) use (&$visit, &$closure, $manifests): void {
            if (isset($closure[$module]) || !isset($manifests[$module])) {
                return;
            }
            $closure[$module] = true;
            /** @var VaulterModule $manifest */
            $manifest = new ($manifests[$module]);
            foreach (array_keys($manifest->requires()) as $required) {
                $visit($required);
            }
        };
        $visit($id);

        $holders = $unreadable = [];
        foreach (array_keys($closure) as $module) {
            $manifest = $manifests[$module];
            if (!is_subclass_of($manifest, HoldsReferences::class)) {
                continue;
            }
            try {
                $references = $this->laravel->make($manifest::heldReferences());
                if ($references instanceof ModuleReferences) {
                    $holders[$module] = $references;
                }
            } catch (Throwable $e) {
                $unreadable[$module] = $e->getMessage();
            }
        }
        ksort($holders, SORT_STRING);

        return [$holders, $unreadable];
    }

    /**
     * Foreign keys of other tables that reference one of `$tables`; null when the database cannot be read.
     *
     * @param  list<string>                                        $tables
     * @return list<array{table: string, references: string}>|null
     */
    private static function incomingForeignKeys(array $tables, Connection $connection): ?array
    {
        if ($tables === []) {
            return [];
        }
        try {
            $schema = $connection->getSchemaBuilder();
            $incoming = [];
            foreach ($schema->getTables() as $info) {
                $table = (string) $info['name'];
                if (in_array($table, $tables, true)) {
                    continue;
                }
                foreach ($schema->getForeignKeys($table) as $fk) {
                    if (in_array($fk['foreign_table'], $tables, true)) {
                        $incoming[] = ['table' => $table, 'references' => $fk['foreign_table']];
                    }
                }
            }

            return $incoming;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The number of rows of each of `$tables` that exists; null when the database cannot be read.
     *
     * @param  list<string>            $tables
     * @return array<string, int>|null
     */
    private static function rows(array $tables, Connection $connection): ?array
    {
        try {
            $rows = [];
            foreach ($tables as $table) {
                if ($connection->getSchemaBuilder()->hasTable($table)) {
                    $rows[$table] = $connection->table($table)->count();
                }
            }

            return $rows;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Runs `down` of the module's migrations that ran, newest first, and forgets them in the migrations table — the
     * migrator's own rollback picks by batch, not by owner. The migrations table is the one `migrate` records in.
     *
     * @param  array<string, string> $migrations name => real path
     * @return list<string>          the migrations rolled back
     */
    private static function rollBack(array $migrations, Migrator $migrator, Filesystem $files): array
    {
        if (!$migrator->repositoryExists()) {
            return [];
        }
        $repository = $migrator->getRepository();
        $ran = $repository->getRan();
        $rolledBack = [];
        foreach (array_reverse($migrations, true) as $name => $path) {
            if (!in_array($name, $ran, true)) {
                continue;
            }
            $migration = $files->getRequire($path);
            if (!$migration instanceof Migration) {
                continue;
            }
            $db = $migrator->resolveConnection($migration->getConnection());
            $down = static function () use ($migration): void {
                if (method_exists($migration, 'down')) {
                    $migration->down();
                }
            };
            $migration->withinTransaction && $db->getSchemaGrammar()->supportsSchemaTransactions()
                ? $db->transaction($down)
                : $down();
            $repository->delete((object) ['migration' => $name]);
            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    /** @param array<string, int> $references */
    private static function describe(array $references): string
    {
        return implode(', ', array_map(static fn (string $kind, int $count): string => $kind.' ('.$count.')', array_keys($references), $references));
    }
}
