<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The dedicated binary-upload policy (uploads.max_file_size + uploads.allowed_mime_types):
 * UploadFileRequest must read its OWN size ceiling and an optional mime allowlist
 * instead of borrowing the TipTap document limit, while staying permissive by
 * default. Storage::fake keeps the slice off the real disk; a bare actor suffices
 * because authorization is a later milestone.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function uploadConfigStorage(): string
{
    return (string) Vaulter::drive(DriveTestHelpers::host())->model()->getKey();
}

it('maxFileSize falls back to the drive default independently of document limits', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', null);
    config()->set('vaulter-documents.content.max_size_kb', 1234);

    expect(app(DriveConfig::class)->maxFileSize())->toBe(5_368_709_120);
});

it('maxFileSize uses the configured byte ceiling independently of document limits', function (): void {
    config()->set('vaulter-documents.content.max_size_kb', 5120);
    config()->set('vaulter-drive.uploads.max_file_size', 64 * 1024);

    expect(app(DriveConfig::class)->maxFileSize())->toBe(64 * 1024);
});

it('allowedUploadMimes is empty (permissive) by default and lists configured mimes', function (): void {
    config()->set('vaulter-drive.uploads.allowed_mime_types', []);
    expect(app(DriveConfig::class)->allowedMimeTypes())->toBe([]);

    config()->set('vaulter-drive.uploads.allowed_mime_types', ['application/pdf', 'image/png']);
    expect(app(DriveConfig::class)->allowedMimeTypes())->toBe(['application/pdf', 'image/png']);
});

it('rejects an upload above the dedicated uploads.max_file_size ceiling', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', 1 * 1024); // 1 KB ceiling

    $base = 'api/vaulter/v1/drives/'.uploadConfigStorage();

    $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->create('big.bin', 4), // 4 KB
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(Node::query()->where('kind', 'file')->count())->toBe(0);
});

it('accepts an upload within the dedicated ceiling regardless of the document limit', function (): void {
    config()->set('vaulter-documents.content.max_size_kb', 1); // would reject under the OLD borrowed limit
    config()->set('vaulter-drive.uploads.max_file_size', 5120 * 1024);

    $base = 'api/vaulter/v1/drives/'.uploadConfigStorage();

    $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('ok.txt', 'within the drive ceiling'),
    ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'file');
});

it('rejects a mime outside a configured allowlist', function (): void {
    config()->set('vaulter-drive.uploads.allowed_mime_types', ['application/pdf']);

    $base = 'api/vaulter/v1/drives/'.uploadConfigStorage();

    $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('note.txt', 'actual plain text'),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(Node::query()->where('kind', 'file')->count())->toBe(0);
});

it('accepts a mime inside a configured allowlist', function (): void {
    config()->set('vaulter-drive.uploads.allowed_mime_types', ['application/pdf', 'text/plain']);

    $base = 'api/vaulter/v1/drives/'.uploadConfigStorage();

    $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('note.txt', 'actual plain text'),
    ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'file');
});

it('stays permissive when no allowlist is configured', function (): void {
    config()->set('vaulter-drive.uploads.allowed_mime_types', []);

    $base = 'api/vaulter/v1/drives/'.uploadConfigStorage();

    $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('anything.xyz', 'unrestricted content'),
    ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'file');
});
