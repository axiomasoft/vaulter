<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Doctor\DoctorRunner;
use Vaulter\Doctor\DoctorSeverity;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/*
 * The media doctor prints the pins per owner type and warns about the owners whose module is not active:
 * their pins stay and keep their blobs, and only the owner's own purge or rollback may release them.
 */

beforeEach(function (): void {
    Storage::fake('local');
});

/** @return list<DoctorFinding> */
function blobPinsFindings(): array
{
    $report = app(DoctorRunner::class)->run(new DoctorContext(production: false, probeStorage: false, sampleLimit: 1000, now: CarbonImmutable::now('UTC')), ['media.blobs.pins']);

    return $report->checks[0]->findings;
}

it('reports no pins on an empty store', function (): void {
    $findings = blobPinsFindings();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(DoctorSeverity::Ok)
        ->and($findings[0]->code)->toBe('media.blobs.pins')
        ->and($findings[0]->message)->toBe('No blob is pinned.');
});

it('prints the pins per owner type and warns only about an owner whose module is not active', function (): void {
    $blob = CasTestHelpers::row(app(BlobStore::class)->commit(CasTestHelpers::streamFor('doctor'), BlobScope::tenant('1')));
    CasTestHelpers::pin($blob, '1:data:0', 'drive.revision_blob');
    CasTestHelpers::pin($blob, '2:data:0', 'drive.revision_blob');
    CasTestHelpers::pin($blob, 'own', 'media.preview');

    $findings = blobPinsFindings();

    // The drive is not installed in the media suite; the media module itself is active.
    expect($findings)->toHaveCount(2)
        ->and($findings[0]->severity)->toBe(DoctorSeverity::Ok)
        ->and($findings[0]->message)->toBe('Pins by owner type: drive.revision_blob=2, media.preview=1.')
        ->and($findings[0]->evidence)->toBe(['drive.revision_blob' => 2, 'media.preview' => 1])
        ->and($findings[1]->severity)->toBe(DoctorSeverity::Warning)
        ->and($findings[1]->code)->toBe('media.blobs.pins_inactive_owner')
        ->and($findings[1]->evidence)->toBe(['owner_type' => 'drive.revision_blob', 'module' => 'drive', 'state' => 'not installed', 'pins' => 2])
        ->and($findings[1]->hint)->toContain('own purge or rollback');
});

it('changes nothing it reports on', function (): void {
    $blob = CasTestHelpers::row(app(BlobStore::class)->commit(CasTestHelpers::streamFor('read only'), BlobScope::tenant('1')));
    CasTestHelpers::pin($blob, 'k', 'ghost.owner');

    blobPinsFindings();
    blobPinsFindings();

    expect(CasTestHelpers::pins($blob))->toBe(1);
});
