<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\Tests\Fixtures\Cas\NonListableContentStore;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    config()->set('vaulter-media.gc.grace_hours', 2);
    config()->set('vaulter-media.gc.orphan_grace_hours', 3);
    Carbon::setTestNow('2026-09-25 12:00:00');
});

it('reports the exact GC phase totals and effective grace', function (): void {
    Artisan::call('vaulter:blobs:gc', ['--grace' => '7']);

    expect(Artisan::output())->toContain(
        'Tombstoned 0, swept 0, skipped 0 (grace 7h).',
    );
});

it('reports the configured GC grace when no override is supplied', function (): void {
    Artisan::call('vaulter:blobs:gc');

    expect(Artisan::output())->toContain(
        'Tombstoned 0, swept 0, skipped 0 (grace 2h).',
    );
});

it('reports the exact pending sweep totals', function (): void {
    $blob = app(BlobStore::class)->commit(CasTestHelpers::streamFor('pending command contract'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));
    $blob->forceFill([
        'status' => BlobStatus::Pending,
        'first_seen_at' => now()->subHours(8),
    ])->save();

    Artisan::call('vaulter:blobs:gc', ['--grace' => '7', '--pending' => true]);

    expect(Artisan::output())
        ->toContain('Tombstoned 0, swept 0, skipped 0 (grace 7h).')
        ->toContain('Pending sweep: swept 1, skipped 0.');
});

it('reports exact reconcile totals and the clean-row verdict', function (): void {
    Artisan::call('vaulter:blobs:reconcile');

    expect(Artisan::output())
        ->toContain('Reconciled 0 blobs, corrected 0 ref_count caches.')
        ->toContain('No dangling rows detected.');
});

it('reports every dangling row identity', function (): void {
    $first = app(BlobStore::class)->commit(CasTestHelpers::streamFor('dangling first'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));
    $second = app(BlobStore::class)->commit(CasTestHelpers::streamFor('dangling second'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));
    Storage::disk('local')->delete([$first->storage_key, $second->storage_key]);

    $exit = Artisan::call('vaulter:blobs:reconcile');

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain(
            'Dangling rows (committed blob, missing object): '.
            $first->getKey().', '.$second->getKey(),
        );
});

it('reports exact orphan walk totals, disk and grace', function (): void {
    $first = CasTestHelpers::seedOrphan(bytes: 'command orphan one', scope: 't-1', ageHours: 48);
    $second = CasTestHelpers::seedOrphan(bytes: 'command orphan two', scope: 't-1', ageHours: 48);
    Storage::disk('local')->put('foreign/readme.txt', 'not CAS');

    $exit = Artisan::call('vaulter:blobs:reconcile', [
        '--orphans' => true,
        '--disk' => 'local',
        '--orphan-grace' => '24',
    ]);

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())
        ->toContain('Orphan walk (report-only): scanned 3, found 2, pruned 0, skipped 1 (disk local, grace 24h).')
        ->toContain('Orphan objects (bytes with no blob row): '.$first.', '.$second);
});

it('reports the explicit orphan disk and the configured grace', function (): void {
    Storage::fake('archive');
    $orphan = CasTestHelpers::seedOrphan(
        bytes: 'archive command orphan',
        scope: 't-1',
        ageHours: 48,
        disk: 'archive',
    );

    $exit = Artisan::call('vaulter:blobs:reconcile', [
        '--orphans' => true,
        '--disk' => 'archive',
    ]);

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())
        ->toContain('Orphan walk (report-only): scanned 1, found 1, pruned 0, skipped 0 (disk archive, grace 3h).')
        ->toContain('Orphan objects (bytes with no blob row): '.$orphan);
});

it('treats an empty disk option as the configured disk', function (): void {
    $exit = Artisan::call('vaulter:blobs:reconcile', [
        '--orphans' => true,
        '--disk' => '',
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain(
            'Orphan walk (report-only): scanned 0, found 0, pruned 0, skipped 0 (disk local, grace 3h).',
        )->not->toContain('Orphan pruning needs PostgreSQL/MySQL');
});

it('reports the exact SQLite prune refusal', function (): void {
    $orphan = CasTestHelpers::seedOrphan(bytes: 'refused command prune', scope: 't-1', ageHours: 48);

    $exit = Artisan::call('vaulter:blobs:reconcile', [
        '--orphans' => true,
        '--prune' => true,
    ]);

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())
        ->toContain('Orphan walk (report-only): scanned 1, found 1, pruned 0, skipped 0 (disk local, grace 3h).')
        ->toContain(
            'Orphan pruning needs PostgreSQL/MySQL (the delete must serialize against concurrent commits); '.
            'on sqlite use report-only or run on the production DB.',
        )
        ->toContain('Orphan objects (bytes with no blob row): '.$orphan);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'The refusal branch is specific to SQLite.',
);

it('stops after reporting that orphan listing is unsupported', function (): void {
    app()->instance(ContentStore::class, new NonListableContentStore);

    $exit = Artisan::call('vaulter:blobs:reconcile', [
        '--orphans' => true,
        '--prune' => true,
    ]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and(Artisan::output())
        ->toContain('Orphan walk: the active CAS driver does not support listing — skipped.')
        ->not->toContain('Orphan walk (report-only)')
        ->not->toContain('Orphan pruning needs PostgreSQL/MySQL');
});

it('warns when prune is requested without an orphan walk', function (): void {
    $exit = Artisan::call('vaulter:blobs:reconcile', ['--prune' => true]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('--prune has no effect without --orphans; nothing pruned.');
});
