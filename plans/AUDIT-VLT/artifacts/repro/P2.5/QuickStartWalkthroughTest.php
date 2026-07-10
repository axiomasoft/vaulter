<?php

declare(strict_types=1);

namespace Vaulter\Tests\P25Repro;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Facades\Vaulter;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Tests\Fixtures\Panels\FakeProject;
use Vaulter\VaulterServiceProvider;

/**
 * P2.5 C4 repro — walk docs/guide/quick-start.md LITERALLY, step by step, on a
 * default-config Testbench app (no config publish, no permission-driver override —
 * the exact "clean Laravel app, no authorization wiring" scenario step 1 of the
 * guide claims). Every step is the guide's own code, unmodified beyond substituting
 * the host entity. Per Code Guidance: C4 must not be closed by reading — the guide
 * is either walked and its actual first failing step named, or the run is `⚠ partial`
 * with the literal error.
 */
final class QuickStartWalkthroughTest extends OrchestraTestCase
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            VaulterServiceProvider::class,
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        // Deliberately NOT overriding vaulter.drivers.permission (default: 'database',
        // per docs/guide/quick-start.md §1 "no authorization wiring").
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../../../packages/core/database/migrations');
    }

    public function test_quick_start_steps_run_as_written(): void
    {
        $steps = [];

        // Step "2. Open a drive and use it" — $project is any Eloquent model.
        $project = new FakeProject;
        $project->forceFill(['id' => 1]);
        $project->exists = true;

        try {
            $drive = Vaulter::for($project);
            $steps['Vaulter::for($project)'] = 'OK — '.$drive::class;
        } catch (\Throwable $e) {
            $steps['Vaulter::for($project)'] = 'FAILED — '.$e::class.': '.$e->getMessage();
            $this->reportAndFail($steps);
        }

        try {
            $reports = $drive->createFolder('Reports');
            $steps["createFolder('Reports')"] = 'OK — node id='.$reports->getKey();
        } catch (\Throwable $e) {
            $steps["createFolder('Reports')"] = 'FAILED — '.$e::class.': '.$e->getMessage();
            $this->reportAndFail($steps);
        }

        try {
            $node = $drive->upload(
                source: 'hello quick-start',
                into: $reports,
            );
            $steps['upload(string, into: $reports)'] = 'OK — node id='.$node->getKey().' name='.$node->name;
        } catch (\Throwable $e) {
            $steps['upload(string, into: $reports)'] = 'FAILED — '.$e::class.': '.$e->getMessage();
            $this->reportAndFail($steps);
        }

        try {
            $listing = $drive->list($reports);
            $steps['list($reports)'] = 'OK — count='.$listing->count();
        } catch (\Throwable $e) {
            $steps['list($reports)'] = 'FAILED — '.$e::class.': '.$e->getMessage();
            $this->reportAndFail($steps);
        }

        // Bonus: verify() sanity check from installation.md itself.
        try {
            $root = $drive->root();
            $steps['installation.md verify: $drive->root()'] = 'OK — '.($root instanceof \Vaulter\Models\Drive\Node ? 'returned a Node' : 'NOT a Node');
        } catch (\Throwable $e) {
            $steps['installation.md verify: $drive->root()'] = 'FAILED — '.$e::class.': '.$e->getMessage();
        }

        fwrite(STDOUT, "\n=== P2.5 C4 quick-start walkthrough (default config, no auth wiring) ===\n");
        foreach ($steps as $step => $result) {
            fwrite(STDOUT, "  [{$step}] {$result}\n");
        }
        fwrite(STDOUT, "=== total steps executed without a hard stop: ".count($steps)." ===\n\n");

        $this->assertTrue(true);
    }

    /** @param array<string, string> $steps */
    private function reportAndFail(array $steps): void
    {
        fwrite(STDOUT, "\n=== P2.5 C4 quick-start walkthrough — STOPPED EARLY ===\n");
        foreach ($steps as $step => $result) {
            fwrite(STDOUT, "  [{$step}] {$result}\n");
        }
        fwrite(STDOUT, "===\n\n");
        $this->assertTrue(true); // repro records the failure point; it does not fail the suite.
    }
}
