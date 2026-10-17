<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Data\RevisionOptions;
use Vaulter\Drive\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Events\Files\FileRevisionAdded;
use Vaulter\Drive\Exceptions\Files\UploadRejectedException;
use Vaulter\Drive\Exceptions\Policy\FileSizeLimitExceededException;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Testing\Fixtures\TestUser;

beforeEach(fn () => Storage::fake('local'));

it('V05 rejects an executable disguised as PDF before SQL and CAS', function (): void {
    $drive = UploadTestHelpers::storage();
    $stream = UploadTestHelpers::streamFor('MZ'.str_repeat("\0", 1024));
    expect(fn () => Vaulter::drive($drive)->asSystem('test fixture')->files()->upload(UploadSource::fromStream($stream, 'report.pdf'), new UploadOptions(declaredMimeType: 'application/pdf')))
        ->toThrow(UploadRejectedException::class);
    expect(Blob::query()->count())->toBe(0)->and(FileRevision::query()->count())->toBe(0)->and(is_resource($stream))->toBeTrue();
    fclose($stream);
});

it('I9 persists detected MIME separately from the declared generic MIME', function (): void {
    $drive = UploadTestHelpers::storage();
    $stream = UploadTestHelpers::streamFor('plain text');
    $node = app(IngestFileAction::class)->execute(DriveContext::system($drive, 'test fixture'), new IngestFileInput($drive, $stream, 'note.txt', 'application/octet-stream'));
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    expect($revision->mime_type)->toBe('text/plain')->and($revision->declared_mime_type)->toBe('application/octet-stream')->and($revision->sha256)->toBe(hash('sha256', 'plain text'));
    fclose($stream);
});

it('I9 ingest receipt names the revision this call created, not the head a post-commit writer moved', function (): void {
    $handle = Vaulter::drive(UploadTestHelpers::storage())->asSystem('test fixture');
    $fileId = $handle->files()->upload(UploadSource::fromString('initial', 'receipt.txt'))->id;
    $later = null;
    Event::listen(FileRevisionAdded::class, function (FileRevisionAdded $event) use ($handle, $fileId, &$later): void {
        if ($event->version->number === 2) {
            app(VaulterDatabase::class)->afterCommit(function () use ($handle, $fileId, &$later): void {
                $later = $handle->files()->addRevision($fileId, UploadSource::fromString('later bytes', 'receipt.txt'), new RevisionOptions(expectedSequence: 1));
            });
        }
    });

    $own = $handle->files()->addRevision($fileId, UploadSource::fromString('own bytes', 'receipt.txt'), new RevisionOptions(expectedSequence: 0));

    expect($later)->toBeInstanceOf(FileRevisionSnapshot::class)
        ->and([$own->number, $own->sha256])->toBe([2, hash('sha256', 'own bytes')])
        ->and([$later->number, $later->sha256])->toBe([3, hash('sha256', 'later bytes')])
        ->and($own->id)->not->toBe($later->id);
});

it('V30 counts from the current position and enforces the actual byte limit', function (): void {
    $stream = UploadTestHelpers::streamFor('prefixactual');
    fread($stream, 6);
    $source = UploadSource::fromStream($stream, 'x.txt', 999);
    $prepared = app(StagedUpload::class)->stageSource($source, 6);
    try {
        expect($prepared->facts->size)->toBe(6)->and($prepared->facts->sha256)->toBe(hash('sha256', 'actual'));
    } finally {
        $prepared->discard();
    }
    expect(is_resource($stream))->toBeTrue();
    rewind($stream);
    expect(fn () => app(StagedUpload::class)->stageSource($source, 2))->toThrow(FileSizeLimitExceededException::class);
    expect(is_resource($stream))->toBeTrue();
    fclose($stream);
});

it('V05 HTTP single tus and S3 reject the same executable PDF bytes before CAS', function (string $protocol): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 12]));
    $drive = Vaulter::drive(DriveTestHelpers::host())->model();
    $bytes = 'MZ'.str_repeat("\0", 1024);
    if ($protocol === 'single') {
        $path = tempnam(sys_get_temp_dir(), 'p37-http-');
        file_put_contents($path, $bytes);
        try {
            $file = new UploadedFile($path, 'report.pdf', 'application/pdf', test: true);
            $this->postJson('api/vaulter/v1/drives/'.$drive->id.'/files', ['file' => $file])->assertStatus(422);
        } finally {
            unlink($path);
        }
    } else {
        $create = $this->postJson('api/vaulter/v1/drives/'.$drive->id.'/uploads', [
            'protocol' => $protocol, 'name' => 'report.pdf', 'mime_type' => 'application/pdf', 'total_size_bytes' => strlen($bytes),
        ])->assertCreated();
        $id = $create->json('data.upload_id');
        if ($protocol === 'tus') {
            $this->call('PATCH', 'api/vaulter/v1/uploads/'.$id, [], [], [], [
                'HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_TUS_RESUMABLE' => '1.0.0',
            ], $bytes)->assertStatus(422);
        } else {
            $this->call('POST', 'api/vaulter/v1/uploads/'.$id.'/parts?part_no=1&byte_offset=0', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $bytes)->assertCreated();
            $this->postJson('api/vaulter/v1/uploads/'.$id.'/complete')->assertStatus(422);
        }
    }
    expect(Blob::query()->count())->toBe(0)->and(FileRevision::query()->count())->toBe(0);
})->with(['single', 'tus', 's3-multipart']);
