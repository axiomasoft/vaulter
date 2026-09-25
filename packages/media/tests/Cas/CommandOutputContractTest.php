<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Media\Tests\Cas\CasTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
    config()->set('vaulter.gc.grace_hours', 2);
    config()->set('vaulter.gc.orphan_grace_hours', 3);
    Carbon::setTestNow('2026-09-25 12:00:00');
});

it('reports the exact GC phase totals and effective grace', function (): void {
    Artisan::call('vaulter:gc-blobs', ['--grace' => '7']);

    expect(Artisan::output())->toContain(
        'Tombstoned 0, swept 0, skipped 0 (grace 7h).',
    );
});

it('reports the exact pending sweep totals', function (): void {
    $blob = app(BlobStore::class)->commit(CasTestHelpers::streamFor('pending command contract'), tenantId: 1);
    $blob->forceFill([
        'status' => BlobStatus::Pending,
        'first_seen_at' => now()->subHours(8),
    ])->save();

    Artisan::call('vaulter:gc-blobs', ['--grace' => '7', '--pending' => true]);

    expect(Artisan::output())
        ->toContain('Tombstoned 0, swept 0, skipped 0 (grace 7h).')
        ->toContain('Pending sweep: swept 1, skipped 0.');
});

it('reports exact reconcile totals and the clean-row verdict', function (): void {
    Artisan::call('vaulter:reconcile-blobs');

    expect(Artisan::output())
        ->toContain('Reconciled 0 blobs, corrected 0 ref_count caches.')
        ->toContain('No dangling rows detected.');
});

it('reports every dangling row identity', function (): void {
    $first = app(BlobStore::class)->commit(CasTestHelpers::streamFor('dangling first'), tenantId: 1);
    $second = app(BlobStore::class)->commit(CasTestHelpers::streamFor('dangling second'), tenantId: 1);
    Storage::disk('local')->delete([$first->storage_key, $second->storage_key]);

    $exit = Artisan::call('vaulter:reconcile-blobs');

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain(
            'Dangling rows (committed blob, missing object): '.
            $first->getKey().', '.$second->getKey(),
        );
});

it('reports exact orphan walk totals, disk and grace', function (): void {
    $first = CasTestHelpers::seedOrphan(bytes: 'command orphan one', scope: 't1', ageHours: 48);
    $second = CasTestHelpers::seedOrphan(bytes: 'command orphan two', scope: 't1', ageHours: 48);
    Storage::disk('local')->put('foreign/readme.txt', 'not CAS');

    $exit = Artisan::call('vaulter:reconcile-blobs', [
        '--orphans' => true,
        '--disk' => 'local',
        '--orphan-grace' => '24',
    ]);

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())
        ->toContain('Orphan walk (report-only): scanned 3, found 2, pruned 0, skipped 1 (disk local, grace 24h).')
        ->toContain('Orphan objects (bytes with no blob row): '.$first.', '.$second);
});

it('warns when prune is requested without an orphan walk', function (): void {
    $exit = Artisan::call('vaulter:reconcile-blobs', ['--prune' => true]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('--prune has no effect without --orphans; nothing pruned.');
});
