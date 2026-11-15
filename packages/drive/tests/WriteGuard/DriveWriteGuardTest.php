<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Exceptions\Database\WriteOutsideOperationException;

/**
 * The drive operations run entirely inside a Vaulter write scope,
 * and the drive's models refuse a write that does not.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter.database.write_guard', true);
    $this->drive = DriveTestHelpers::drive();
});

it('runs folder, upload, revision, move, copy, trash, restore and purge under the guard', function (): void {
    $folder = $this->drive->folders()->create('Docs');
    $file = $this->drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('one'), 'a.txt'), new UploadOptions(parent: $folder));
    $revision = $this->drive->files()->addRevision($file, UploadSource::fromStream(DriveTestHelpers::streamFor('two'), 'a.txt'));
    $this->drive->files()->setRevisionRetention($file, $revision, true);
    $copy = $this->drive->nodes()->copy($file, $this->drive->root(), new CopyOptions(name: 'b.txt'));
    $this->drive->nodes()->move($copy, $folder);
    $this->drive->actingAs(DriveTestHelpers::actor($this->drive, 5))->nodes()->star($file);
    $this->drive->nodes()->setProperties($file, 'app', ['label' => 'x']);
    $trashed = $this->drive->nodes()->trash($folder);
    $restored = $this->drive->trash()->restore($trashed);
    $this->drive->nodes()->trash($restored);
    $this->drive->trash()->purge($restored);

    expect(Node::query()->whereKey($folder->id)->exists())->toBeFalse();
});

it('completes uploads and shares through their own scopes', function (): void {
    $this->actingAs(DriveTestHelpers::actor($this->drive, 1));
    $this->postJson('api/vaulter/v1/drives/'.$this->drive->model()->getKey().'/files', [
        'file' => UploadedFile::fake()->createWithContent('x.txt', 'x'),
    ])->assertCreated();
});

it('refuses a direct write to the drive models', function (): void {
    $file = $this->drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('one'), 'a.txt'));

    expect(fn () => Drive::query()->whereKey($this->drive->model()->getKey())->update(['used_bytes' => 0]))->toThrow(WriteOutsideOperationException::class)
        ->and(fn () => File::query()->where('node_id', $file->id)->update(['size_bytes' => 0]))->toThrow(WriteOutsideOperationException::class)
        ->and(fn () => FileRevision::query()->delete())->toThrow(WriteOutsideOperationException::class);
});
