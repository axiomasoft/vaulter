<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The dedicated binary-upload policy (drive.max_upload_kb + drive.allowed_mime):
 * UploadFileRequest must read its OWN size ceiling and an optional mime allowlist
 * instead of borrowing the TipTap document limit, while staying permissive by
 * default. Storage::fake keeps the slice off the real disk; a bare actor suffices
 * because authorization is a later milestone.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function uploadConfigStorage(): string
{
    return (string) Vaulter::for(DriveTestHelpers::host())->model()->getKey();
}

it('maxUploadKb falls back to the document size limit when unset', function (): void {
    config()->set('vaulter.drive.max_upload_kb', null);
    config()->set('vaulter.max_document_size_kb', 1234);

    expect(Config::maxUploadKb())->toBe(1234);
});

it('maxUploadKb prefers its own configured value over the document limit', function (): void {
    config()->set('vaulter.max_document_size_kb', 5120);
    config()->set('vaulter.drive.max_upload_kb', 64);

    expect(Config::maxUploadKb())->toBe(64);
});

it('allowedUploadMimes is empty (permissive) by default and lists configured mimes', function (): void {
    config()->set('vaulter.drive.allowed_mime', []);
    expect(Config::allowedUploadMimes())->toBe([]);

    config()->set('vaulter.drive.allowed_mime', ['application/pdf', 'image/png']);
    expect(Config::allowedUploadMimes())->toBe(['application/pdf', 'image/png']);
});

it('rejects an upload above the dedicated drive.max_upload_kb ceiling', function (): void {
    config()->set('vaulter.drive.max_upload_kb', 1); // 1 KB ceiling

    $base = 'api/vaulter/storages/'.uploadConfigStorage();

    $this->postJson($base.'/uploads', [
        'file' => UploadedFile::fake()->create('big.bin', 4), // 4 KB
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(Node::query()->where('kind', 'file')->count())->toBe(0);
});

it('accepts an upload within the dedicated ceiling regardless of the document limit', function (): void {
    config()->set('vaulter.max_document_size_kb', 1); // would reject under the OLD borrowed limit
    config()->set('vaulter.drive.max_upload_kb', 5120);

    $base = 'api/vaulter/storages/'.uploadConfigStorage();

    $this->postJson($base.'/uploads', [
        'file' => UploadedFile::fake()->createWithContent('ok.txt', 'within the drive ceiling'),
    ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'file');
});

it('rejects a mime outside a configured allowlist', function (): void {
    config()->set('vaulter.drive.allowed_mime', ['application/pdf']);

    $base = 'api/vaulter/storages/'.uploadConfigStorage();

    $this->postJson($base.'/uploads', [
        'file' => UploadedFile::fake()->create('note.txt', 1, 'text/plain'),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(Node::query()->where('kind', 'file')->count())->toBe(0);
});

it('accepts a mime inside a configured allowlist', function (): void {
    config()->set('vaulter.drive.allowed_mime', ['application/pdf', 'text/plain']);

    $base = 'api/vaulter/storages/'.uploadConfigStorage();

    $this->postJson($base.'/uploads', [
        'file' => UploadedFile::fake()->create('note.txt', 1, 'text/plain'),
    ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'file');
});

it('stays permissive when no allowlist is configured', function (): void {
    config()->set('vaulter.drive.allowed_mime', []);

    $base = 'api/vaulter/storages/'.uploadConfigStorage();

    $this->postJson($base.'/uploads', [
        'file' => UploadedFile::fake()->create('anything.xyz', 1, 'application/octet-stream'),
    ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'file');
});
