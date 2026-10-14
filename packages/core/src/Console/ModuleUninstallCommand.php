<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleState;
use Vaulter\Modules\VaulterModule;

/**
 * Plans the removal of a module's schema (D36): its migrations and tables, the installed modules that
 * require it, and the foreign keys other owners hold on its tables. A dry run by default; `--force`
 * (roll the owner's migrations back) and `--purge` (delete its rows and pins) refuse until P4 splits the
 * create-migrations by owner. Never drops a table it does not own, and disabling a module never calls it.
 */
final class ModuleUninstallCommand extends Command
{
    protected $signature = 'vaulter:module:uninstall
        {module : The module id}
        {--force : Roll back the module\'s migrations}
        {--purge : Also delete the module\'s rows and its own media pins}';

    protected $description = 'Plan (dry run) or perform the removal of a Vaulter module\'s schema';

    public function handle(Kernel $kernel, DatabaseConfig $database): int
    {
        if (!$kernel->isValid()) {
            $this->components->error('The module graph is invalid; run vaulter:modules for the reasons.');

            return self::FAILURE;
        }
        $id = (string) $this->argument('module');
        $module = $kernel->compiled()->module($id);
        if ($module === null) {
            $this->components->error('No installed module is called "'.$id.'".');

            return self::FAILURE;
        }

        $migrations = self::migrations($module);
        $tables = array_map(static fn (string $entity): string => $database->table($entity), self::entities($migrations));
        $dependents = self::dependents($id, $kernel->modules());
        $incoming = self::incomingForeignKeys($tables, $database->connection());

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

        $this->components->twoColumnDetail('Module', $id.($module->active ? ' (active)' : ' (inactive)'));
        $this->components->twoColumnDetail('Migrations', $migrations === [] ? 'none of its own — the create-migrations ship with core until P4' : (string) count($migrations));
        foreach ($migrations as $migration) {
            $this->line('  '.OutputFormatter::escape($migration));
        }
        $this->components->twoColumnDetail('Tables', $tables === [] ? 'none' : implode(', ', $tables));
        $this->components->twoColumnDetail('Required by', $dependents === [] ? 'no installed module' : implode(', ', $dependents));
        $this->components->twoColumnDetail('Incoming foreign keys', $incoming === null ? 'unknown (no database connection)' : ($incoming === [] ? 'none' : (string) count($incoming)));
        foreach ($refusals as $refusal) {
            $this->line('  <fg=red>refused:</> '.OutputFormatter::escape($refusal));
        }

        if ($this->option('force') === true || $this->option('purge') === true) {
            $this->components->error('owner migrations are not split yet (P4): --force and --purge are not available.');

            return self::FAILURE;
        }
        $this->components->info('Dry run: nothing was changed.');

        return $refusals === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> the migration files of the module's schema paths, sorted */
    private static function migrations(ModuleState $module): array
    {
        $files = [];
        foreach ($module->schemaPaths as $path) {
            foreach (glob(rtrim($path, '/').'/*.php') ?: [] as $file) {
                $files[] = basename($file, '.php');
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The entities the create-migrations create (`…_create_v_<entity>_table`).
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
     * Foreign keys of other tables that reference one of `$tables`; null when the database cannot be read.
     *
     * @param  list<string>                                        $tables
     * @return list<array{table: string, references: string}>|null
     */
    private static function incomingForeignKeys(array $tables, ?string $connection): ?array
    {
        if ($tables === []) {
            return [];
        }
        try {
            $schema = Schema::connection($connection);
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
}
