<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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

it('sweep помечает протухшие открытые сессии expired и чистит staged temp', function (): void {
    $storage = UploadTestHelpers::storage();

    $stale = UploadTestHelpers::session($storage, totalSizeBytes: 100);
    // Stage some bytes so there is a temp to reclaim.
    app(TusUploadHandler::class)->patch($stale->refresh(), UploadTestHelpers::streamFor('partial'), 0);
    // Force it past its TTL.
    $stale->forceFill(['expires_at' => Carbon::now()->subHour()])->save();

    $stagedKey = app(StagedUpload::class)->keyFor($stale);
    Storage::disk('local')->assertExists($stagedKey);

    $this->artisan('vaulter:sweep-uploads')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(UploadStatus::Expired);
    Storage::disk('local')->assertMissing($stagedKey);
});

it('sweep НЕ трогает свежие (не протухшие) сессии', function (): void {
    $storage = UploadTestHelpers::storage();

    $fresh = UploadTestHelpers::session($storage, totalSizeBytes: 100);
    $fresh->forceFill(['expires_at' => Carbon::now()->addHour()])->save();

    $this->artisan('vaulter:sweep-uploads')->assertSuccessful();

    expect($fresh->refresh()->status)->toBe(UploadStatus::Initiated);
});

it('sweep НЕ трогает уже завершённые/aborted сессии', function (): void {
    $storage = UploadTestHelpers::storage();

    $committed = UploadTestHelpers::session($storage);
    $committed->forceFill([
        'status' => UploadStatus::Committed,
        'expires_at' => Carbon::now()->subDay(),
    ])->save();

    $this->artisan('vaulter:sweep-uploads')->assertSuccessful();

    // A terminal session past its TTL is NOT swept (the sweeper only acts on open ones).
    expect($committed->refresh()->status)->toBe(UploadStatus::Committed);
});

it('sweepable scope выбирает initiated|uploading|assembled в границах expires_at (F-P1.4-10)', function (): void {
    // Contract updated by F-P1.4-10: Assembled is now a sweep candidate too (a
    // session stuck Assembled past its TTL — commit interrupted after every byte
    // landed — used to leak its staged temp / S3 parts forever).
    $storage = UploadTestHelpers::storage();
    $threshold = Carbon::now();

    // Distinct, staggered expires_at (the scope orders by it) so the assertion
    // below is not at the mercy of insertion-order ties.
    // initiated + stale → swept.
    UploadTestHelpers::session($storage)->forceFill(['expires_at' => $threshold->copy()->subHours(3)])->save();
    // uploading + stale → swept.
    UploadTestHelpers::session($storage)->forceFill([
        'status' => UploadStatus::Uploading,
        'expires_at' => $threshold->copy()->subHours(2),
    ])->save();
    // assembled + stale → NOW in the sweepable set (F-P1.4-10).
    UploadTestHelpers::session($storage)->forceFill([
        'status' => UploadStatus::Assembled,
        'expires_at' => $threshold->copy()->subHour(),
    ])->save();

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

it('sweep сметает застрявшую Assembled-сессию за TTL и чистит staged-байты (F-P1.4-10, red-before: остаётся)', function (): void {
    $storage = UploadTestHelpers::storage();

    $stuck = UploadTestHelpers::session($storage, totalSizeBytes: 10);
    app(TusUploadHandler::class)->patch($stuck->refresh(), UploadTestHelpers::streamFor('1234567890'), 0);
    // Commit was interrupted after every byte landed: force Assembled, past TTL.
    $stuck->forceFill(['status' => UploadStatus::Assembled, 'expires_at' => Carbon::now()->subHour()])->save();

    $stagedKey = app(StagedUpload::class)->keyFor($stuck);
    Storage::disk('local')->assertExists($stagedKey);

    $this->artisan('vaulter:sweep-uploads')->assertSuccessful();

    expect($stuck->refresh()->status)->toBe(UploadStatus::Expired);
    Storage::disk('local')->assertMissing($stagedKey);
});

it('sweep НЕ трогает свежую Assembled-сессию в пределах TTL (F-P1.4-10, контроль)', function (): void {
    $storage = UploadTestHelpers::storage();

    $fresh = UploadTestHelpers::session($storage, totalSizeBytes: 10);
    app(TusUploadHandler::class)->patch($fresh->refresh(), UploadTestHelpers::streamFor('1234567890'), 0);
    // Assembled but still well within its TTL — a valid in-flight commit, not stuck.
    $fresh->forceFill(['status' => UploadStatus::Assembled, 'expires_at' => Carbon::now()->addHour()])->save();

    $stagedKey = app(StagedUpload::class)->keyFor($fresh);

    $this->artisan('vaulter:sweep-uploads')->assertSuccessful();

    expect($fresh->refresh()->status)->toBe(UploadStatus::Assembled);
    Storage::disk('local')->assertExists($stagedKey);
});

it('sweep абортит S3-multipart части протухшей сессии', function (): void {
    $storage = UploadTestHelpers::storage();

    $stale = UploadTestHelpers::session($storage, protocol: UploadProtocol::S3Multipart, totalSizeBytes: 10);
    app(S3MultipartHandler::class)
        ->putPart($stale->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('1234567890'));
    $stale->forceFill(['expires_at' => Carbon::now()->subHour()])->save();

    Storage::disk('local')->assertExists("tmp/uploads/{$stale->upload_id}.parts/1");

    $this->artisan('vaulter:sweep-uploads')->assertSuccessful();

    Storage::disk('local')->assertMissing("tmp/uploads/{$stale->upload_id}.parts/1");
    expect($stale->refresh()->status)->toBe(UploadStatus::Expired);
});
