<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Kernel;
use Vaulter\Modules\GuardsRemoval;
use Vaulter\Modules\HoldsReferences;
use Vaulter\Modules\ModuleReferences;
use Vaulter\Modules\ModuleState;
use Vaulter\Modules\RemovalGuard;
use Vaulter\Modules\VaulterModule;

/**
 * Plans, and on `--force` performs, the removal of a module's schema (D36): its migrations and tables, the installed
 * modules that require it, the foreign keys other owners hold on its tables, the references (media pins, D23) kept on
 * its data or by it, and what its removal guard reports (the collaboration edits not in a checkpoint yet, D26). A dry
 * run by default. `--force` rolls back the migrations of the module's own schema paths (newest first) — only while its
 * tables hold no row and it keeps no reference; `--purge` (with `--force`) first deletes its rows and releases its own
 * references. It never drops, deletes or releases anything of another owner, and disabling a module never calls it.
 *
 * Disable the module in the configuration (`vaulter.modules.<id>`) and stop its traffic before `--force`: no lock keeps
 * its writers out. What the dry run read is read again — inside the transaction of the purge, and right before the
 * rollback of every migration — and any row, reference or guard blocker that has appeared stops the removal (fail
 * closed: nothing further is touched). That narrows the window of a concurrent writer, it does not close it.
 *
 * Atomicity: where DDL is transactional (sqlite, pgsql) the purge and the rollbacks run in one transaction, so a failure
 * leaves nothing changed. Where DDL commits (MySQL) the purge commits before the rollbacks; a failure after it leaves the
 * module applied partially — repeat `--force` (exit 3).
 *
 * Exit codes: 0 planned or done, 1 refused or failed (nothing changed), 2 usage, 3 applied partially (see above).
 */
#[Internal]
final class ModuleUninstallCommand extends Command
{
    /** Applied partially: the purge or some rollbacks are done, the rest is not (DDL that cannot be rolled back). */
    private const int PARTIAL = 3;

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
        $closure = self::requirementClosure($id, $kernel->modules());
        [$holders, $unreadable] = $this->holders($closure);
        [$guards, $unguarded] = $this->guards($closure);
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
        $guarded = [];
        foreach ($guards as $guardian => $guard) {
            try {
                $guarded = [...$guarded, ...self::guardBlockers($id, $guardian, $guard)];
            } catch (Throwable $e) {
                $unguarded[$guardian] = $e->getMessage();
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
        $refusals = [...$refusals, ...$guarded];

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
        if ($guards !== [] || $unguarded !== []) {
            $this->components->twoColumnDetail('Removal guards', $guarded === [] ? 'nothing blocks it' : count($guarded).' blocker(s)');
        }
        foreach ($unguarded as $guardian => $reason) {
            $this->components->twoColumnDetail('Removal guard of '.$guardian, 'unknown ('.OutputFormatter::escape($reason).')');
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
        foreach ($unguarded as $guardian => $reason) {
            $blocked[] = sprintf('the removal guard of module "%s" cannot be read (%s)', $guardian, $reason);
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

        $this->components->warn(sprintf(
            'Disable "%s" (vaulter.modules.%s) and stop its traffic first: writers are not locked out. Rows or references that appear meanwhile stop the removal, but the window cannot be closed.',
            $id,
            $id,
        ));

        $steps = self::steps($migrations, $migrator, $files);
        // Laravel flags only pgsql as transactional DDL, yet SQLite's DDL is transactional too.
        $atomic = $connection->getSchemaGrammar()->supportsSchemaTransactions() || $connection->getDriverName() === 'sqlite';
        foreach ($steps as $step) {
            $atomic = $atomic && $step['db']->getName() === $connection->getName();
        }
        $guard = static fn (bool $strict): array => self::blockers($id, $tables, $holders, $guards, $connection, $strict);
        $refusal = new RuntimeException('The removal was refused.');
        $blockers = $rolledBack = [];
        $purged = [0, 0];
        $failure = null;

        try {
            if ($atomic) {
                // One transaction: a refusal or a failure of any step leaves the purge undone too.
                [$purged, $rolledBack] = $database->transaction(static function () use ($purge, $holders, $id, $tables, $rows, $connection, $steps, $migrator, $guard, $refusal, &$blockers): array {
                    $blockers = $guard(!$purge);
                    $purged = [0, 0];
                    $done = [];
                    if ($blockers === []) {
                        $purged = $purge ? self::purge($holders, $id, $tables, $rows ?? [], $connection) : $purged;
                        $blockers = self::rollBack($steps, $migrator, static fn (): array => $guard(true), $done);
                    }
                    if ($blockers !== []) {
                        throw $refusal;
                    }

                    return [$purged, $done];
                });
            } else {
                if ($purge) {
                    // DDL commits on this driver: the purge is its own transaction, the rollbacks follow it.
                    $purged = $database->transaction(static function () use ($holders, $id, $tables, $rows, $connection, $guard, $refusal, &$blockers): array {
                        $blockers = $guard(false);
                        if ($blockers !== []) {
                            throw $refusal;
                        }

                        return self::purge($holders, $id, $tables, $rows ?? [], $connection);
                    });
                }
                $blockers = self::rollBack($steps, $migrator, static fn (): array => $guard(true), $rolledBack);
            }
        } catch (Throwable $e) {
            if ($e !== $refusal) {
                $failure = $e->getMessage();
                $blockers = [];
            }
        }

        $changed = $purged[0] + $purged[1] > 0 || $rolledBack !== [];
        if ($blockers !== [] || $failure !== null) {
            foreach ($blockers as $blocker) {
                $this->line('  <fg=red>refused:</> '.OutputFormatter::escape($blocker));
            }
            if ($failure !== null) {
                $this->line('  <fg=red>failed:</> '.OutputFormatter::escape($failure));
            }
            if (!$changed) {
                $this->components->error('The module was not uninstalled: nothing was changed.');

                return self::FAILURE;
            }
            // Only a driver whose DDL commits gets here: the transaction of the atomic path left nothing behind.
            $this->components->twoColumnDetail('Purged', sprintf('%d row(s), %d reference(s)', $purged[0], $purged[1]));
            foreach ($rolledBack as $migration) {
                $this->components->twoColumnDetail(OutputFormatter::escape($migration), '<fg=green;options=bold>ROLLED BACK</>');
            }
            $this->components->error('The module "'.$id.'" was applied partially: the purge or some rollbacks are done, the rest is not. Fix the cause and repeat --force (with --purge if rows or references remain).');

            return self::PARTIAL;
        }

        if ($purge) {
            $this->components->twoColumnDetail('Purged', sprintf('%d row(s), %d reference(s)', $purged[0], $purged[1]));
        }
        foreach ($rolledBack as $migration) {
            $this->components->twoColumnDetail(OutputFormatter::escape($migration), '<fg=green;options=bold>ROLLED BACK</>');
        }
        $this->components->info($rolledBack === []
            ? 'Nothing to roll back: the schema of "'.$id.'" is not installed.'
            : 'The schema of "'.$id.'" is removed. Remove its package from Composer, or `migrate` installs it again.');

        return self::SUCCESS;
    }

    /**
     * What stops the removal now, read again: references kept on the module's data, what the removal guards report,
     * and — when `$strict`, as after the purge or without it — rows of its tables and references it keeps. A fact that
     * cannot be read stops it too.
     *
     * @param  list<string>                    $tables
     * @param  array<string, ModuleReferences> $holders
     * @param  array<string, RemovalGuard>     $guards
     * @return list<string>
     */
    private static function blockers(string $id, array $tables, array $holders, array $guards, Connection $connection, bool $strict): array
    {
        $blockers = [];
        foreach ($guards as $guardian => $guard) {
            try {
                $blockers = [...$blockers, ...self::guardBlockers($id, $guardian, $guard)];
            } catch (Throwable $e) {
                $blockers[] = sprintf('the removal guard of module "%s" cannot be read (%s)', $guardian, $e->getMessage());
            }
        }
        try {
            foreach ($holders as $holder => $references) {
                if ($holder === $id) {
                    foreach ($references->keptOn($id) as $kind => $count) {
                        $blockers[] = sprintf('%d reference(s) of "%s" are kept on its data; only the purge of their owner releases them', $count, $kind);
                    }
                }
                if ($strict) {
                    foreach ($references->keptBy($id) as $kind => $count) {
                        $blockers[] = sprintf('it keeps %d reference(s) of "%s" in %s; --purge releases them', $count, $kind, $holder);
                    }
                }
            }
            if ($strict) {
                $rows = self::rows($tables, $connection);
                if ($rows === null) {
                    $blockers[] = 'the database cannot be read';
                } elseif (array_sum($rows) > 0) {
                    $blockers[] = 'its tables hold rows; --purge deletes them';
                }
            }
        } catch (Throwable $e) {
            $blockers[] = 'the references cannot be read ('.$e->getMessage().')';
        }

        return $blockers;
    }

    /**
     * Releases the references the module keeps and deletes its rows, inside the caller's transaction.
     *
     * @param  array<string, ModuleReferences> $holders
     * @param  list<string>                    $tables
     * @param  array<string, int>              $rows
     * @return array{0: int, 1: int}           rows deleted, references released
     */
    private static function purge(array $holders, string $id, array $tables, array $rows, Connection $connection): array
    {
        $released = 0;
        foreach ($holders as $references) {
            $released += $references->releaseKeptBy($id);
        }
        $deleted = 0;
        // A table after the ones it references, so the reverse order deletes the referencing rows first.
        foreach (array_reverse($tables) as $table) {
            if (array_key_exists($table, $rows)) {
                $deleted += $connection->table($table)->delete();
            }
        }

        return [$deleted, $released];
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
     * What one removal guard reports for `$id`, as refusals.
     *
     * @return list<string>
     */
    private static function guardBlockers(string $id, string $guardian, RemovalGuard $guard): array
    {
        $blockers = [];
        foreach ($guard->blockers($id) as $what => $count) {
            if ($count > 0) {
                $blockers[] = sprintf('module "%s" refuses it: %d %s', $guardian, $count, $what);
            }
        }

        return $blockers;
    }

    /**
     * The module `$id` and the modules it requires, directly or not, with their manifest classes: the holders of the
     * references it may keep or that are kept on its data (a reference, like a foreign key, points along a Composer
     * dependency, D6), and the guards of its removal.
     *
     * @param  list<ModuleState>                          $modules
     * @return array<string, class-string<VaulterModule>> module id => manifest class
     */
    private static function requirementClosure(string $id, array $modules): array
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
            $closure[$module] = $manifests[$module];
            /** @var VaulterModule $manifest */
            $manifest = new ($manifests[$module]);
            foreach (array_keys($manifest->requires()) as $required) {
                $visit($required);
            }
        };
        $visit($id);

        return $closure;
    }

    /**
     * The references of the modules in `$closure` that hold some.
     *
     * @param  array<string, class-string<VaulterModule>>                          $closure
     * @return array{0: array<string, ModuleReferences>, 1: array<string, string>} holder id => its references; holder id => why it cannot be read
     */
    private function holders(array $closure): array
    {
        $holders = $unreadable = [];
        foreach ($closure as $module => $manifest) {
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
     * The removal guards of the modules in `$closure` that have one.
     *
     * @param  array<string, class-string<VaulterModule>>                      $closure
     * @return array{0: array<string, RemovalGuard>, 1: array<string, string>} module id => its guard; module id => why it cannot be read
     */
    private function guards(array $closure): array
    {
        $guards = $unreadable = [];
        foreach ($closure as $module => $manifest) {
            if (!is_subclass_of($manifest, GuardsRemoval::class)) {
                continue;
            }
            try {
                $guard = $this->laravel->make($manifest::removalGuard());
                if (!$guard instanceof RemovalGuard) {
                    throw new RuntimeException(sprintf('%s is not a %s', get_debug_type($guard), RemovalGuard::class));
                }
                $guards[$module] = $guard;
            } catch (Throwable $e) {
                $unreadable[$module] = $e->getMessage();
            }
        }
        ksort($guards, SORT_STRING);
        ksort($unreadable, SORT_STRING);

        return [$guards, $unreadable];
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
     * The migrations of the module that ran, newest first, with the connection each runs on — the migrator's own
     * rollback picks by batch, not by owner. The migrations table is the one `migrate` records in.
     *
     * @param  array<string, string>                                           $migrations name => real path
     * @return list<array{name: string, migration: Migration, db: Connection}>
     */
    private static function steps(array $migrations, Migrator $migrator, Filesystem $files): array
    {
        if (!$migrator->repositoryExists()) {
            return [];
        }
        $ran = $migrator->getRepository()->getRan();
        $steps = [];
        foreach (array_reverse($migrations, true) as $name => $path) {
            if (!in_array($name, $ran, true)) {
                continue;
            }
            $migration = $files->getRequire($path);
            if ($migration instanceof Migration) {
                $steps[] = ['name' => $name, 'migration' => $migration, 'db' => $migrator->resolveConnection($migration->getConnection())];
            }
        }

        return $steps;
    }

    /**
     * Runs `down` of the steps and forgets them in the migrations table; `$guard` is read right before each one and, when
     * it names a blocker, nothing further is touched. `$done` lists the migrations rolled back so far, also when `down`
     * throws.
     *
     * @param  list<array{name: string, migration: Migration, db: Connection}> $steps
     * @param  Closure(): list<string>                                         $guard
     * @param  list<string>                                                    $done
     * @return list<string>                                                    the blockers that stopped it; empty when every step ran
     */
    private static function rollBack(array $steps, Migrator $migrator, Closure $guard, array &$done): array
    {
        $repository = $migrator->getRepository();
        foreach ($steps as $step) {
            $blockers = $guard();
            if ($blockers !== []) {
                return $blockers;
            }
            $migration = $step['migration'];
            $down = static function () use ($migration): void {
                if (method_exists($migration, 'down')) {
                    $migration->down();
                }
            };
            $migration->withinTransaction && $step['db']->getSchemaGrammar()->supportsSchemaTransactions()
                ? $step['db']->transaction($down)
                : $down();
            $repository->delete((object) ['migration' => $step['name']]);
            $done[] = $step['name'];
        }

        return [];
    }

    /** @param array<string, int> $references */
    private static function describe(array $references): string
    {
        return implode(', ', array_map(static fn (string $kind, int $count): string => $kind.' ('.$count.')', array_keys($references), $references));
    }
}
