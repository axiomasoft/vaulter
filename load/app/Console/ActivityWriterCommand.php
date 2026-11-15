<?php

declare(strict_types=1);

namespace Vaulter\Load\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vaulter\Support\Config;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * The sustained background writer Deliverables call for: without a
 * live write tail during a k6 run, P3's read-path profiling would measure v_activity
 * under a static GIN index, not the write-hot path it actually ships as
 * ("sustained write-RPS во время прогона"). Run this
 * ALONGSIDE a k6 scenario, not as part of `load:seed` (Scope Excluded) — it
 * has no natural stopping point of its own.
 *
 * Paced in one-second ticks: sleeps out the remainder of each second after its
 * batch insert, so a slow tick (GC pressure, lock contention) shortens the NEXT
 * sleep rather than compounding drift across the run.
 */
final class ActivityWriterCommand extends Command
{
    protected $signature = 'load:activity-write
        {--rps= : Rows written per second — defaults to the tier profile (reference-env.md §2.1)}
        {--tier= : Volume profile S0|S|M — defaults to $TIER, only used to resolve the default --rps}
        {--duration=0 : Seconds to run, 0 = until Ctrl-C}';

    protected $description = 'Sustained-RPS v_activity background writer for a k6 run (harness.md §2, reference-env.md §2.1).';

    private const array VERBS = ['node.created', 'file.uploaded', 'version.created', 'node.moved', 'node.trashed', 'grant.created'];

    public function handle(): int
    {
        $rps = (int) ($this->option('rps') ?: $this->defaultRps());

        if ($rps <= 0) {
            $this->components->error('Pass --rps or --tier (S0|S|M) with a resolvable profile.');

            return self::FAILURE;
        }

        $storageRow = $this->connection()->table(Config::table('storages'))->orderBy('id')->first();

        if ($storageRow === null) {
            $this->components->error('No v_storages row found — run `load:seed` first.');

            return self::FAILURE;
        }

        $nodeIds = $this->connection()->table(Config::table('nodes'))
            ->where('storage_id', $storageRow->id)
            ->inRandomOrder()
            ->limit(2_000)
            ->pluck('id')
            ->all();

        if ($nodeIds === []) {
            $this->components->error('No v_nodes rows to attribute activity to — run `load:seed` first.');

            return self::FAILURE;
        }

        $duration = (int) $this->option('duration');
        $deadline = $duration > 0 ? microtime(true) + $duration : null;
        $activityTable = Config::table('activity');
        $actorType = (new TestUser)->getMorphClass();
        $nodeModel = Config::nodeModel();
        $nodeMorphType = (new $nodeModel)->getMorphClass();
        $written = 0;

        $this->components->info("Writing {$rps} rows/s of v_activity (storage_id={$storageRow->id})".($duration > 0 ? " for {$duration}s" : ' until Ctrl-C').'.');

        while ($deadline === null || microtime(true) < $deadline) {
            $tickStart = microtime(true);
            $rows = [];

            for ($i = 0; $i < $rps; $i++) {
                $rows[] = [
                    'storage_id' => $storageRow->id,
                    'actor_id' => 1,
                    'actor_type' => $actorType,
                    'verb' => self::VERBS[array_rand(self::VERBS)],
                    'target_id' => $nodeIds[array_rand($nodeIds)],
                    'target_type' => $nodeMorphType,
                    'context' => json_encode(['load_tail' => true]),
                    'created_at' => Carbon::now(),
                ];
            }

            $this->connection()->table($activityTable)->insert($rows);
            $written += count($rows);

            $elapsed = microtime(true) - $tickStart;
            $remaining = 1.0 - $elapsed;

            if ($remaining > 0) {
                usleep((int) ($remaining * 1_000_000));
            }
        }

        $this->components->info("Wrote {$written} v_activity rows.");

        return self::SUCCESS;
    }

    private function defaultRps(): int
    {
        $tier = strtoupper((string) ($this->option('tier') ?: env('TIER', '')));
        $path = dirname(__DIR__, 2)."/seed/profiles/{$tier}.php"; // base_path() is Testbench's OWN skeleton, not this repo — see SeedLoadDatasetCommand::loadRoot().

        if ($tier === '' || !is_file($path)) {
            return 0;
        }

        /** @var array<string, mixed> $profile */
        $profile = require $path;

        return (int) ($profile['activity']['sustained_write_rps'] ?? 0);
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(Config::connection());
    }
}
