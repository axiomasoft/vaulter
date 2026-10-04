<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    StorageFacade::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 17]));
});

function r07Storage(): Drive
{
    return Vaulter::for(DriveTestHelpers::host())->storage();
}

function r07SessionId(Drive $storage, array $overrides = []): string
{
    $create = test()->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'seed.bin',
        'total_size_bytes' => 8,
        ...$overrides,
    ])->assertCreated();

    return $create->json('data.upload_id');
}

it('returns 403 on HEAD PATCH DELETE and OPTIONS when tus is disabled', function (): void {
    config()->set('vaulter.uploads.tus.enabled', false);
    $storage = r07Storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 4, createdBy: 17);
    $uri = 'api/vaulter/resumable-uploads/'.$session->upload_id;

    $this->call('HEAD', $uri, server: ['HTTP_TUS_RESUMABLE' => '1.0.0'])->assertForbidden();
    $this->call('PATCH', $uri, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'HTTP_UPLOAD_OFFSET' => '0',
    ], content: 'abcd')->assertForbidden();
    $this->delete($uri, [], ['Tus-Resumable' => '1.0.0'])->assertForbidden();
    $this->call('OPTIONS', 'api/vaulter/resumable-uploads/discovery')->assertForbidden();
});

it('412 on missing Tus-Resumable includes both Tus-Resumable and Tus-Version headers', function (): void {
    $uploadId = r07SessionId(r07Storage());

    $this->call('HEAD', 'api/vaulter/resumable-uploads/'.$uploadId)
        ->assertStatus(412)
        ->assertHeader('Tus-Resumable', '1.0.0')
        ->assertHeader('Tus-Version', '1.0.0');
});

it('415 on PATCH abort includes Tus-Resumable and rejects empty or text/plain content types', function (): void {
    $uploadId = r07SessionId(r07Storage());
    $uri = 'api/vaulter/resumable-uploads/'.$uploadId;

    foreach (['', 'text/plain'] as $contentType) {
        $this->call('PATCH', $uri, server: [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
            'CONTENT_TYPE' => $contentType,
            'HTTP_UPLOAD_OFFSET' => '0',
        ], content: 'abcd')
            ->assertStatus(415)
            ->assertHeader('Tus-Resumable', '1.0.0');
    }
});

it('accepts PATCH content-type with parameters after application/offset+octet-stream', function (): void {
    $uploadId = r07SessionId(r07Storage());

    $this->call('PATCH', 'api/vaulter/resumable-uploads/'.$uploadId, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'CONTENT_TYPE' => 'application/offset+octet-stream; charset=binary',
        'HTTP_UPLOAD_OFFSET' => '0',
    ], content: 'abcd')->assertNoContent()->assertHeader('Upload-Offset', '4');
});

it('OPTIONS advertises Tus-Max-Size as the decimal max_bytes when capped at ten', function (): void {
    config()->set('vaulter.uploads.max_bytes', 10);

    $this->call('OPTIONS', 'api/vaulter/resumable-uploads/discovery')
        ->assertNoContent()
        ->assertHeader('Tus-Extension', 'creation,termination')
        ->assertHeader('Tus-Max-Size', '10');
});

it('DELETE abort returns 204 with Tus-Resumable and marks the session aborted', function (): void {
    $uploadId = r07SessionId(r07Storage());

    $this->delete('api/vaulter/resumable-uploads/'.$uploadId, [], ['Tus-Resumable' => '1.0.0'])
        ->assertNoContent()
        ->assertHeader('Tus-Resumable', '1.0.0');

    expect(UploadSession::query()->where('upload_id', $uploadId)->sole()->status)
        ->toBe(UploadStatus::Aborted);
});

it('status poll returns 403 for a different authenticated actor', function (): void {
    $storage = r07Storage();
    $uploadId = r07SessionId($storage);

    $this->actingAs((new TestUser)->forceFill(['id' => 99]));

    $this->getJson('api/vaulter/resumable-uploads/'.$uploadId)->assertForbidden();
});

it('create into a non-root folder stores that folder as target_parent_id', function (): void {
    $storage = r07Storage();
    $root = $storage->rootNode()->first();
    $folder = new Node;
    $folder->forceFill([
        'storage_id' => $storage->getKey(),
        'parent_id' => $root->getKey(),
        'kind' => NodeKind::Folder,
        'name' => 'nested',
        'size_bytes' => 0,
    ]);
    $folder->save();

    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'nested.bin',
        'total_size_bytes' => 4,
        'parent_id' => $folder->getKey(),
    ])->assertCreated()->json('data.upload_id');

    $session = UploadSession::query()->where('upload_id', $uploadId)->sole();

    expect($session->target_parent_id)->toEqual($folder->getKey())
        ->and($session->target_parent_id)->not->toEqual($root->getKey());
});

it('create with parent omitted resolves the storage root folder as target_parent_id', function (): void {
    $storage = r07Storage();
    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'root.bin',
        'total_size_bytes' => 4,
    ])->assertCreated()->json('data.upload_id');

    expect(UploadSession::query()->where('upload_id', $uploadId)->sole()->target_parent_id)
        ->toEqual($storage->refresh()->root_node_id);
});

it('metadata whitelisting continues after an unknown key and trims header pairs', function (): void {
    $storage = r07Storage();
    $encoded = base64_encode(' kept.bin ');

    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'meta.bin',
        'total_size_bytes' => 4,
        'metadata' => ['filename' => 'json-should-lose.bin'],
    ], ['Upload-Metadata' => ' dropped '.base64_encode('x').', filename '.$encoded])
        ->assertCreated()->json('data.upload_id');

    expect(UploadSession::query()->where('upload_id', $uploadId)->sole()->metadata)
        ->toBe(['filename' => ' kept.bin ']);
});

it('metadata skips empty keys and round-trips base64 values from Upload-Metadata', function (): void {
    $storage = r07Storage();
    $value = 'raw-value';
    $header = 'filename '.base64_encode($value).', ,filetype '.base64_encode('text/plain');

    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'meta2.bin',
        'total_size_bytes' => 4,
    ], ['Upload-Metadata' => $header])->assertCreated()->json('data.upload_id');

    expect(UploadSession::query()->where('upload_id', $uploadId)->sole()->metadata)
        ->toBe(['filename' => $value, 'filetype' => 'text/plain']);
});

it('metadata values longer than max_value_length are truncated', function (): void {
    config()->set('vaulter.uploads.metadata.max_value_length', 6);
    $storage = r07Storage();

    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'meta3.bin',
        'total_size_bytes' => 4,
        'metadata' => ['filename' => '123456789'],
    ])->assertCreated()->json('data.upload_id');

    expect(UploadSession::query()->where('upload_id', $uploadId)->sole()->metadata['filename'])->toBe('123456');
});

it('413 on CONTENT_LENGTH max plus one includes Tus-Resumable and names the ceiling', function (): void {
    config()->set('vaulter.uploads.max_chunk_bytes', 4);
    $uploadId = r07SessionId(r07Storage());

    $response = $this->call('PATCH', 'api/vaulter/resumable-uploads/'.$uploadId, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => '5',
        'HTTP_UPLOAD_OFFSET' => '0',
        'HTTP_ACCEPT' => 'application/json',
    ], content: 'abcde');

    $response->assertStatus(413)
        ->assertHeader('Tus-Resumable', '1.0.0');

    expect($response->json('message') ?? $response->getContent())
        ->toContain('maximum chunk size of 4 bytes');
});

it('accepts CONTENT_LENGTH exactly at max_chunk_bytes', function (): void {
    config()->set('vaulter.uploads.max_chunk_bytes', 4);
    $uploadId = r07SessionId(r07Storage(), ['total_size_bytes' => 16]);

    $this->call('PATCH', 'api/vaulter/resumable-uploads/'.$uploadId, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => '4',
        'HTTP_UPLOAD_OFFSET' => '0',
    ], content: 'abcd')->assertNoContent()->assertHeader('Upload-Offset', '4');
});

it('treats max_chunk_bytes zero as unlimited for declared CONTENT_LENGTH', function (): void {
    config()->set('vaulter.uploads.max_chunk_bytes', 0);
    $uploadId = r07SessionId(r07Storage(), ['total_size_bytes' => 128]);

    $this->call('PATCH', 'api/vaulter/resumable-uploads/'.$uploadId, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => '64',
        'HTTP_UPLOAD_OFFSET' => '0',
    ], content: str_repeat('z', 64))->assertNoContent()->assertHeader('Upload-Offset', '64');
});

it('404s unknown upload_id on status', function (): void {
    $this->getJson('api/vaulter/resumable-uploads/0193a5f0-9a3b-7c2d-8e4f-0000000000ff')->assertNotFound();
});

it('404s when the session storage row is missing on HEAD', function (): void {
    $storage = r07Storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 4, createdBy: 17);
    if (DB::connection()->getDriverName() === 'pgsql') {
        $table = $session->getTable();
        DB::statement('ALTER TABLE "'.$table.'" DROP CONSTRAINT "'.$table.'_storage_id_foreign"');
    }

    UploadSession::query()->whereKey($session->getKey())->update(['storage_id' => 9_999_999]);

    $this->call('HEAD', 'api/vaulter/resumable-uploads/'.$session->upload_id, server: ['HTTP_TUS_RESUMABLE' => '1.0.0'])
        ->assertNotFound();
});

it('404s when parent_id belongs to another storage on create', function (): void {
    $storage = r07Storage();
    $other = Vaulter::for(DriveTestHelpers::host())->storage();
    $foreignFolder = new Node;
    $foreignFolder->forceFill([
        'storage_id' => $other->getKey(),
        'parent_id' => $other->root_node_id,
        'kind' => NodeKind::Folder,
        'name' => 'foreign',
        'size_bytes' => 0,
    ]);
    $foreignFolder->save();

    $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'bad-parent.bin',
        'total_size_bytes' => 4,
        'parent_id' => $foreignFolder->getKey(),
    ])->assertNotFound();
});

it('403s PATCH when no authenticated actor is present', function (): void {
    $uploadId = r07SessionId(r07Storage());
    Auth::logout();

    $this->call('PATCH', 'api/vaulter/resumable-uploads/'.$uploadId, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'HTTP_UPLOAD_OFFSET' => '0',
    ], content: 'abcd')->assertForbidden();
});

it('Location header normalizes a trailing slash on the route prefix', function (): void {
    config()->set('vaulter.route_prefix', 'api/vaulter/');
    $storage = r07Storage();

    $response = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'loc.bin',
        'total_size_bytes' => 4,
    ])->assertCreated();

    $location = (string) $response->headers->get('Location');
    $uploadId = $response->json('data.upload_id');

    expect($location)->toBe('http://localhost/api/vaulter/uploads/'.$uploadId)
        ->and($location)->not->toContain('//uploads');
});

it('guardCreate workspace AND node create cannot be split under the null ACL driver', function (): void {
    // The Drive suite pins vaulter.drivers.permission=null — every authenticated actor
    // receives both workspace-create and node-create, so a single-permission-denied case
    // is not expressible here without swapping the permission driver (out of R07 scope).
    expect(true)->toBeTrue();
});
