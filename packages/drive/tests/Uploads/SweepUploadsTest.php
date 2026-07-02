<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\Enums\UploadProtocol;
use Vaulter\Drive\Uploads\Enums\UploadStatus;
use Vaulter\Drive\Uploads\Models\UploadSession;
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

it('sweepable scope выбирает только initiated|uploading в границах expires_at', function (): void {
    $storage = UploadTestHelpers::storage();
    $threshold = Carbon::now();

    // initiated + stale → swept.
    UploadTestHelpers::session($storage)->forceFill(['expires_at' => $threshold->copy()->subHour()])->save();
    // uploading + stale → swept.
    UploadTestHelpers::session($storage)->forceFill([
        'status' => UploadStatus::Uploading,
        'expires_at' => $threshold->copy()->subHour(),
    ])->save();
    // assembled + stale → NOT in the open set.
    UploadTestHelpers::session($storage)->forceFill([
        'status' => UploadStatus::Assembled,
        'expires_at' => $threshold->copy()->subHour(),
    ])->save();

    $candidates = UploadSession::query()->sweepable($threshold)->get();

    expect($candidates)->toHaveCount(2)
        ->and($candidates->pluck('status')->map->value->all())
        ->toEqual([UploadStatus::Initiated->value, UploadStatus::Uploading->value]);
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
