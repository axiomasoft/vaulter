<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\UpgradePreflight;

/** Read-only check that 0.2 data can move to the 0.3 schema. */
final class UpgradeInspectCommand extends Command
{
    protected $signature = 'vaulter:upgrade:inspect {--json : Print the report as JSON}';

    protected $description = 'Report 0.2 data that blocks the 0.3 schema upgrade (read-only)';

    public function handle(UpgradePreflight $preflight, VaulterDatabase $database): int
    {
        $problems = $preflight->problems($database->connection());

        if ($this->option('json') === true) {
            $this->line(json_encode([
                'schema' => 'vaulter.upgrade-inspect/v1',
                'ready' => $problems === [],
                'problems' => $problems,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } elseif ($problems === []) {
            $this->components->info('ready');
        } else {
            $this->components->error('Upgrade blocked by '.count($problems).' problem(s):');
            foreach ($problems as $problem) {
                $this->line(' - '.$problem);
            }
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
