<?php

declare(strict_types=1);

use Vaulter\Tests\VerifyFP22_02Clean\CleanSequenceBase;

require __DIR__.'/CleanSequenceBase.php';

uses(CleanSequenceBase::class);

it('step 1: fresh migrate() with NO prior published files runs cleanly via vendor auto-run', function () {
    $before = glob(database_path('migrations/*.php'));
    dump('database/migrations BEFORE step 1 (must be empty) = '.json_encode(array_map('basename', $before)));

    $exit = $this->artisan('migrate', ['--force' => true])->run();

    dump('step 1 migrate exit code = '.$exit);
    expect($exit)->toBe(0);
    expect(\Illuminate\Support\Facades\Schema::hasTable('v_storages'))->toBeTrue();
});

it('step 2: host publishes migrations later (to customize schema, per docs/guide/installation.md:75-80) — new timestamped copies land in database/migrations', function () {
    // Step 1 equivalent state: run migrate once via vendor auto-run first.
    $this->artisan('migrate', ['--force' => true])->run();
    expect(\Illuminate\Support\Facades\Schema::hasTable('v_storages'))->toBeTrue();

    // Now the host decides to publish for customization.
    $publishExit = $this->artisan('vendor:publish', ['--tag' => 'vaulter-migrations', '--force' => true])->run();
    dump('vendor:publish --tag=vaulter-migrations exit = '.$publishExit);

    $publishedNow = glob(database_path('migrations/*.php'));
    dump('database/migrations AFTER publish = '.json_encode(array_map('basename', $publishedNow), JSON_PRETTY_PRINT));

    expect(count($publishedNow))->toBeGreaterThan(0);
});

it('step 3: host re-runs migrate after publishing — reproduces F-P2.2-02 (table already exists) or documents that it does not', function () {
    // Recreate the exact host sequence in ONE test (Testbench resets DB between it() blocks
    // but NOT necessarily the filesystem migrations dir — re-publish explicitly to be sure).
    $this->artisan('migrate', ['--force' => true])->run();
    expect(\Illuminate\Support\Facades\Schema::hasTable('v_storages'))->toBeTrue();

    $this->artisan('vendor:publish', ['--tag' => 'vaulter-migrations', '--force' => true])->run();

    $publishedNow = glob(database_path('migrations/*.php'));
    dump('database/migrations right before step-3 re-migrate = '.json_encode(array_map('basename', $publishedNow), JSON_PRETTY_PRINT));

    $exit = $this->artisan('migrate', ['--force' => true])->run();
    $output = \Illuminate\Support\Facades\Artisan::output();

    dump('step 3 (re-migrate after publish) exit code = '.$exit);
    dump('step 3 output = '.$output);

    // Не жёсткий assert — фиксируем фактическое поведение как доказательство.
    expect(true)->toBeTrue();
});
