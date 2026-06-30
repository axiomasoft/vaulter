<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Tests\Cas\CasTestHelpers;
use Vaulter\Tests\Fixtures\Cas\NonListableContentStore;

/**
 * The vaulter:reconcile-blobs --orphans console wiring (master plan P4 #11 / H.3):
 * the command surfaces the orphan disk-walk and maps its result to an exit code so
 * cron/CI sees the hazard. The contract pinned here:
 *
 *  - --orphans that FINDS an orphan (report-only) exits FAILURE and lists it;
 *  - --orphans --prune on sqlite is REFUSED (prints the "needs PostgreSQL/MySQL"
 *    guidance), leaves the bytes, and still exits FAILURE (the orphan is still there);
 *  - a clean disk exits SUCCESS;
 *  - a non-listable driver is reported "listing unsupported", not failed.
 *
 * Time is frozen so the grace arithmetic is deterministic.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
    Carbon::setTestNow('2026-06-30 12:00:00');
    // Floor the orphan grace to 1h so a 48h-old seeded object is comfortably past it.
    config()->set('vaulter.gc.orphan_grace_hours', 1);
});

it('vaulter:reconcile-blobs --orphans с orphan → FAILURE и объект в выводе', function (): void {
    $orphanKey = CasTestHelpers::seedOrphan(bytes: 'cmd orphan', scope: 't1', ageHours: 48);

    $this->artisan('vaulter:reconcile-blobs', ['--orphans' => true])
        ->expectsOutputToContain('Orphan walk (report-only)')
        ->expectsOutputToContain($orphanKey)
        ->assertExitCode(Command::FAILURE);

    // Report-only never deletes.
    Storage::disk('local')->assertExists($orphanKey);
});

it('vaulter:reconcile-blobs --orphans --prune на sqlite: отказ, байты живы, FAILURE', function (): void {
    $orphanKey = CasTestHelpers::seedOrphan(bytes: 'cmd prune refused', scope: 't1', ageHours: 48);

    $this->artisan('vaulter:reconcile-blobs', ['--orphans' => true, '--prune' => true])
        ->expectsOutputToContain('Orphan pruning needs PostgreSQL/MySQL')
        ->assertExitCode(Command::FAILURE);

    // The prune was refused on sqlite — the orphan bytes survive.
    Storage::disk('local')->assertExists($orphanKey);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'the FAILURE exit is the SQLite prune-refusal premise; PG/MySQL prune succeeds.',
);

it('vaulter:reconcile-blobs --orphans на чистом диске → SUCCESS', function (): void {
    // No orphan seeded — a clean disk reconciles cleanly.
    $this->artisan('vaulter:reconcile-blobs', ['--orphans' => true])
        ->expectsOutputToContain('Orphan walk (report-only)')
        ->assertExitCode(Command::SUCCESS);
});

it('vaulter:reconcile-blobs --orphans: не-listable драйвер сообщает "listing unsupported", не падает на этом', function (): void {
    // A genuine aged orphan is present, but the active store cannot enumerate — the
    // walk is skipped cleanly; with no dangling row the command still SUCCEEDs.
    CasTestHelpers::seedOrphan(bytes: 'unwalkable cmd', scope: 't1', ageHours: 48);
    app()->instance(ContentStore::class, new NonListableContentStore);

    $this->artisan('vaulter:reconcile-blobs', ['--orphans' => true, '--prune' => true])
        ->expectsOutputToContain('does not support listing')
        ->assertExitCode(Command::SUCCESS);
});

it('vaulter:reconcile-blobs --orphan-grace переопределяет окно (свежий объект не orphan)', function (): void {
    // A 2h-old object: past the 1h floor, but a 24h --orphan-grace keeps it fresh.
    CasTestHelpers::seedOrphan(bytes: 'within override grace', scope: 't1', ageHours: 2);

    $this->artisan('vaulter:reconcile-blobs', ['--orphans' => true, '--orphan-grace' => 24])
        ->expectsOutputToContain('grace 24h')
        ->assertExitCode(Command::SUCCESS);
});
