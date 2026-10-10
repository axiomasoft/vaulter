<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Exceptions\Uploads\OffsetMismatchException;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Operations\OperationContext;
use Vaulter\Support\LoadTier;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\ValueObjects\Access\Actor;

beforeEach(fn () => DriveTestHelpers::insideOperation());

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);
    $this->app->forgetInstance(MetricsRecorder::class);
    LoadTier::set('S');
});

it('records tus upload bytes and chunk duration on PATCH', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = app(UploadSessionManager::class)->initiate(context: OperationContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'chunk.bin',
        totalSizeBytes: 10,
        mimeType: 'application/octet-stream',
    ));

    app(TusUploadHandler::class)->patch(
        $session->refresh(),
        UploadTestHelpers::streamFor('1234567890'),
        0,
    );

    $recorder = app(MetricsRecorder::class);
    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
    $recorder->assertCounter(name: 'vaulter_upload_bytes_total', labels: ['protocol' => 'tus']);
    $recorder->assertHistogram(
        name: 'vaulter_upload_chunk_duration_seconds',
        labels: ['protocol' => 'tus', 'tier' => 'S'],
    );
    expect($recorder->emissions())->not->toBeEmpty();
});

it('records tus upload conflict on offset mismatch', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = app(UploadSessionManager::class)->initiate(context: OperationContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $storage->rootNode()->first(),
        targetName: 'conflict.bin',
        totalSizeBytes: 20,
        mimeType: 'application/octet-stream',
    ));

    $handler = app(TusUploadHandler::class);
    $handler->patch($session->refresh(), UploadTestHelpers::streamFor('firstchunk'), 0);

    expect(fn () => $handler->patch($session->refresh(), UploadTestHelpers::streamFor('wrong'), 5))
        ->toThrow(OffsetMismatchException::class);

    $recorder = app(MetricsRecorder::class);
    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
    $recorder->assertCounter(
        name: 'vaulter_upload_conflict_total',
        labels: ['protocol' => 'tus'],
    );
    expect($recorder->emissions())->not->toBeEmpty();
});

it('records s3 upload bytes, chunk duration, and assemble metrics on complete', function (): void {
    $part1 = str_repeat('A', 12);
    $part2 = str_repeat('B', 8);
    $whole = $part1.$part2;

    $storage = UploadTestHelpers::storage();
    $session = app(UploadSessionManager::class)->initiate(context: OperationContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::S3Multipart,
        targetParent: $storage->rootNode()->first(),
        targetName: 'multi.bin',
        totalSizeBytes: strlen($whole),
        mimeType: 'application/octet-stream',
    ));

    $handler = app(S3MultipartHandler::class);
    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor($part1));
    $handler->putPart($session->refresh(), partNo: 2, byteOffset: 12, chunk: UploadTestHelpers::streamFor($part2));
    $handler->complete($session->refresh());

    $recorder = app(MetricsRecorder::class);
    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
    $recorder->assertCounter(name: 'vaulter_upload_bytes_total', labels: ['protocol' => 's3']);
    $recorder->assertHistogram(
        name: 'vaulter_upload_chunk_duration_seconds',
        labels: ['protocol' => 's3', 'tier' => 'S'],
    );
    $recorder->assertCounter(
        name: 'vaulter_assemble_bytes_total',
        labels: ['size_bucket' => 'le_8mib'],
    );
    $recorder->assertHistogram(
        name: 'vaulter_assemble_duration_seconds',
        labels: ['size_bucket' => 'le_8mib'],
    );
    expect($recorder->emissions())->not->toBeEmpty();
});

it('records single-shot upload bytes and chunk duration', function (): void {
    $drive = DriveTestHelpers::drive();
    $storage = $drive->model();
    $root = $drive->root();

    $context = new OperationContext(
        drive: $storage,
        actor: Actor::user(DriveTestHelpers::actor($drive, 1)),
        correlationId: 'single-shot-metrics',
    );
    app(IngestFileAction::class)->execute($context, new IngestFileInput(
        storage: $storage,
        into: DriveTestHelpers::model($root),
        stream: DriveTestHelpers::streamFor('single-shot'),
        originalName: 'one.bin',
        mimeType: 'application/octet-stream',
    ));

    $recorder = app(MetricsRecorder::class);
    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
    $recorder->assertCounter(name: 'vaulter_upload_bytes_total', labels: ['protocol' => 'single']);
    $recorder->assertHistogram(
        name: 'vaulter_upload_chunk_duration_seconds',
        labels: ['protocol' => 'single', 'tier' => 'S'],
    );
    expect($recorder->emissions())->not->toBeEmpty();
});

it('records download ttfb and bytes for the current file stream', function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    $drive = DriveTestHelpers::drive();
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('file-bytes'), 'current.bin'));

    $response = $this->get('api/vaulter/v1/files/'.$file->id.'/download');
    $response->assertOk();
    expect($response->streamedContent())->toBe('file-bytes');

    $recorder = app(MetricsRecorder::class);
    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
    $recorder->assertHistogram(
        name: 'vaulter_download_ttfb_seconds',
        labels: ['tier' => 'S'],
    );
    $recorder->assertCounter(
        name: 'vaulter_download_bytes_total',
        labels: ['source' => 'file'],
    );
    expect($recorder->emissions())->not->toBeEmpty();
});

it('records download ttfb and bytes for a historical version stream', function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    $drive = DriveTestHelpers::drive();
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('first-ver'), 'ver.bin'));
    $firstVersionId = DriveTestHelpers::model($file)->file()->value('current_revision_id');

    $drive->files()->addRevision($file, UploadSource::fromStream(DriveTestHelpers::streamFor('second-ver'), 'ver.bin'));

    $response = $this->get(sprintf(
        'api/vaulter/v1/files/%s/revisions/%s/download',
        $file->id,
        $firstVersionId,
    ));
    $response->assertOk();
    expect($response->streamedContent())->toBe('first-ver');

    $recorder = app(MetricsRecorder::class);
    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
    $recorder->assertHistogram(
        name: 'vaulter_download_ttfb_seconds',
        labels: ['tier' => 'S'],
    );
    $recorder->assertCounter(
        name: 'vaulter_download_bytes_total',
        labels: ['source' => 'version'],
    );
    expect($recorder->emissions())->not->toBeEmpty();
});
