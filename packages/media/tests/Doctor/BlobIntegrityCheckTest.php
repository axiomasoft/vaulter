<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Doctor\DoctorRunner;
use Vaulter\Doctor\DoctorSeverity;
use Vaulter\Media\Cas\BlobScrubber;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/*
 * The doctor check media.blobs.integrity (D49): a warning while a blob is marked by the integrity scrub, ok otherwise;
 * it only reads.
 */

beforeEach(function (): void {
    Storage::fake('local');
});

/** @return list<DoctorFinding> */
function blobIntegrityFindings(int $sampleLimit = 1000): array
{
    $report = app(DoctorRunner::class)->run(new DoctorContext(production: false, probeStorage: false, sampleLimit: $sampleLimit, now: CarbonImmutable::now('UTC')), ['media.blobs.integrity']);

    return $report->checks[0]->findings;
}

it('is ok when no blob is marked', function (): void {
    app(BlobStore::class)->commit(CasTestHelpers::streamFor('fine'), BlobScope::tenant('1'));
    app(BlobScrubber::class)->scrub();

    $findings = blobIntegrityFindings();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(DoctorSeverity::Ok)
        ->and($findings[0]->code)->toBe('media.blobs.integrity');
});

it('warns about the marked blobs and names the way to heal them', function (): void {
    $store = app(BlobStore::class);
    $first = $store->commit(CasTestHelpers::streamFor('first damaged'), BlobScope::tenant('1'));
    $second = $store->commit(CasTestHelpers::streamFor('second damaged'), BlobScope::tenant('1'));
    $store->commit(CasTestHelpers::streamFor('third is fine'), BlobScope::tenant('1'));
    Storage::disk('local')->delete($first->storage_key);
    Storage::disk('local')->put($second->storage_key, 'x');
    app(BlobScrubber::class)->scrub();

    $findings = blobIntegrityFindings();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(DoctorSeverity::Warning)
        ->and($findings[0]->code)->toBe('media.blobs.integrity')
        ->and($findings[0]->evidence['failed'])->toBe(2)
        ->and($findings[0]->evidence['blobs'])->toContain((string) $first->getKey())->toContain((string) $second->getKey())
        ->and($findings[0]->hint)->toContain('upload the same bytes again');
});

it('lists no more blobs than the sample limit', function (): void {
    $store = app(BlobStore::class);
    foreach (['a damaged', 'b damaged', 'c damaged'] as $bytes) {
        Storage::disk('local')->delete($store->commit(CasTestHelpers::streamFor($bytes), BlobScope::tenant('1'))->storage_key);
    }
    app(BlobScrubber::class)->scrub();

    $findings = blobIntegrityFindings(sampleLimit: 2);

    expect($findings[0]->evidence['failed'])->toBe(3)
        ->and(explode(',', (string) $findings[0]->evidence['blobs']))->toHaveCount(2);
});

it('goes back to ok after the ingest heals the blobs, and changes nothing it reports on', function (): void {
    $store = app(BlobStore::class);
    $blob = $store->commit(CasTestHelpers::streamFor('heal then ok'), BlobScope::tenant('1'));
    Storage::disk('local')->delete($blob->storage_key);
    app(BlobScrubber::class)->scrub();
    $before = DB::table((new Blob)->getTable())->get()->all();

    blobIntegrityFindings();
    blobIntegrityFindings();
    $store->commit(CasTestHelpers::streamFor('heal then ok'), BlobScope::tenant('1'));

    expect(DB::table((new Blob)->getTable())->count())->toBe(count($before))
        ->and(blobIntegrityFindings()[0]->severity)->toBe(DoctorSeverity::Ok);
});
