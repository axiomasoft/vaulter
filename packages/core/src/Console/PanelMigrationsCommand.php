<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Vaulter\Panels\PanelMigrationGenerator;
use Vaulter\Panels\PanelRegistry;

/**
 * Generates the per-panel Drive migration for a panel that opted into the rare
 * advanced own-tables mode (D12): resolve the panel from the registry, hand it to
 * PanelMigrationGenerator. A panel writing to the shared v_* schema is rejected by
 * the generator — the default path needs no generated file.
 */
final class PanelMigrationsCommand extends Command
{
    protected $signature = 'vaulter:panel:migrations
        {name : The panel id whose per-panel tables to generate}
        {--force : Overwrite an existing migration for this panel}';

    protected $description = 'Generate the per-panel Drive migration for an own-tables storage panel (D12)';

    public function handle(PanelRegistry $registry, PanelMigrationGenerator $generator): int
    {
        /** @var string $name */
        $name = $this->argument('name');

        try {
            $panel = $registry->get($name);

            $file = $generator->generate(
                panel: $panel,
                force: (bool) $this->option('force'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Generated per-panel migration: {$file}");

        return self::SUCCESS;
    }
}
