<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;

beforeEach(function (): void {
    Storage::fake('local');
});

it('sweep marks stale open sessions expired and cleans the staged temp', function (): void {
    $storage = UploadTestHelpers::storage();

    $stale = UploadTestHelpers::session($storage, totalSizeBytes: 100);
    // Stage some bytes so there is a temp to reclaim.
    app(TusUploadHandler::class)->patch($stale->refresh(), UploadTestHelpers::streamFor('partial'), 0);
    // Force it past its TTL.
    app(VaulterDatabase::class)->write(fn () => $stale->forceFill(['expires_at' => Carbon::now()->subHour()])->save());

    $stagedKey = app(StagedUpload::class)->keyFor($stale);
    Storage::disk('local')->assertExists($stagedKey);

    $this->artisan('vaulter:uploads:sweep')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(UploadStatus::Expired);
    Storage::disk('local')->assertMissing($stagedKey);
});

it('sweep does NOT touch fresh (unexpired) sessions', function (): void {
    $storage = UploadTestHelpers::storage();

    $fresh = UploadTestHelpers::session($storage, totalSizeBytes: 100);
    app(VaulterDatabase::class)->write(fn () => $fresh->forceFill(['expires_at' => Carbon::now()->addHour()])->save());

    $this->artisan('vaulter:uploads:sweep')->assertSuccessful();

    expect($fresh->refresh()->status)->toBe(UploadStatus::Initiated);
});

it('sweep does NOT touch completed or aborted sessions', function (): void {
    $storage = UploadTestHelpers::storage();

    $committed = UploadTestHelpers::session($storage);
    app(VaulterDatabase::class)->write(fn () => $committed->forceFill([
        'status' => UploadStatus::Committed,
        'expires_at' => Carbon::now()->subDay(),
    ])->save());

    $this->artisan('vaulter:uploads:sweep')->assertSuccessful();

    // A terminal session past its TTL is NOT swept (the sweeper only acts on open ones).
    expect($committed->refresh()->status)->toBe(UploadStatus::Committed);
});

it('the sweepable scope selects initiated|uploading|assembled within expires_at', function (): void {
    // Assembled is a sweep candidate too (a
    // session stuck Assembled past its TTL — commit interrupted after every byte
    // landed — used to leak its staged temp / S3 parts forever).
    $storage = UploadTestHelpers::storage();
    $threshold = Carbon::now();

    // Distinct, staggered expires_at (the scope orders by it) so the assertion
    // below is not at the mercy of insertion-order ties.
    // initiated + stale → swept.
    app(VaulterDatabase::class)->write(fn () => UploadTestHelpers::session($storage)->forceFill(['expires_at' => $threshold->copy()->subHours(3)])->save());
    // uploading + stale → swept.
    app(VaulterDatabase::class)->write(fn () => UploadTestHelpers::session($storage)->forceFill([
        'status' => UploadStatus::Uploading,
        'expires_at' => $threshold->copy()->subHours(2),
    ])->save());
    // assembled + stale → NOW in the sweepable set.
    app(VaulterDatabase::class)->write(fn () => UploadTestHelpers::session($storage)->forceFill([
        'status' => UploadStatus::Assembled,
        'expires_at' => $threshold->copy()->subHour(),
    ])->save());

    // The model scope itself makes no ordering promise (only the read-repository's
    // sweepable() wraps it with orderBy('expires_at')) — order by id here so the
    // assertion checks MEMBERSHIP, not an incidental row order.
    $candidates = UploadSession::query()->sweepable($threshold)->orderBy('id')->get();

    expect($candidates)->toHaveCount(3)
        ->and($candidates->pluck('status')->map->value->all())
        ->toEqual([
            UploadStatus::Initiated->value,
            UploadStatus::Uploading->value,
            UploadStatus::Assembled->value,
        ]);
});

it('sweep clears a stuck Assembled session past its TTL and cleans the staged bytes', function (): void {
    $storage = UploadTestHelpers::storage();

    $stuck = UploadTestHelpers::session($storage, totalSizeBytes: 10);
    app(TusUploadHandler::class)->patch($stuck->refresh(), UploadTestHelpers::streamFor('1234567890'), 0);
    // Commit was interrupted after every byte landed: force Assembled, past TTL.
    app(VaulterDatabase::class)->write(fn () => $stuck->forceFill(['status' => UploadStatus::Assembled, 'expires_at' => Carbon::now()->subHour()])->save());

    $stagedKey = app(StagedUpload::class)->keyFor($stuck);
    Storage::disk('local')->assertExists($stagedKey);

    $this->artisan('vaulter:uploads:sweep')->assertSuccessful();

    expect($stuck->refresh()->status)->toBe(UploadStatus::Expired);
    Storage::disk('local')->assertMissing($stagedKey);
});

it('sweep does NOT touch a fresh Assembled session within its TTL (control)', function (): void {
    $storage = UploadTestHelpers::storage();

    $fresh = UploadTestHelpers::session($storage, totalSizeBytes: 10);
    app(TusUploadHandler::class)->patch($fresh->refresh(), UploadTestHelpers::streamFor('1234567890'), 0);
    // Assembled but still well within its TTL — a valid in-flight commit, not stuck.
    app(VaulterDatabase::class)->write(fn () => $fresh->forceFill(['status' => UploadStatus::Assembled, 'expires_at' => Carbon::now()->addHour()])->save());

    $stagedKey = app(StagedUpload::class)->keyFor($fresh);

    $this->artisan('vaulter:uploads:sweep')->assertSuccessful();

    expect($fresh->refresh()->status)->toBe(UploadStatus::Assembled);
    Storage::disk('local')->assertExists($stagedKey);
});

it('sweep aborts the S3 multipart parts of a stale session', function (): void {
    $storage = UploadTestHelpers::storage();

    $stale = UploadTestHelpers::session($storage, protocol: UploadProtocol::S3Multipart, totalSizeBytes: 10);
    app(S3MultipartHandler::class)
        ->putPart($stale->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('1234567890'));
    app(VaulterDatabase::class)->write(fn () => $stale->forceFill(['expires_at' => Carbon::now()->subHour()])->save());

    Storage::disk('local')->assertExists("tmp/uploads/{$stale->upload_id}.parts/1");

    $this->artisan('vaulter:uploads:sweep')->assertSuccessful();

    Storage::disk('local')->assertMissing("tmp/uploads/{$stale->upload_id}.parts/1");
    expect($stale->refresh()->status)->toBe(UploadStatus::Expired);
});
