<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Enums\Events\EventType;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * Forces the download controller down the PHP streaming branch (same helper as DriveApiTest).
 */
final class R07StreamingOnlyStorageDriver implements StorageDriver
{
    public function __construct(private readonly StorageDriver $inner) {}

    public function disk(?string $disk = null): Filesystem
    {
        return $this->inner->disk($disk);
    }

    public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool
    {
        return $this->inner->put($key, $stream, $disk, $ifNoneMatch, $options);
    }

    public function get(string $key, ?string $disk = null): mixed
    {
        return $this->inner->get($key, $disk);
    }

    public function exists(string $key, ?string $disk = null): bool
    {
        return $this->inner->exists($key, $disk);
    }

    public function delete(string $key, ?string $disk = null): void
    {
        $this->inner->delete($key, $disk);
    }

    public function temporaryUrl(string $key, DateTimeInterface $expiresAt, ?string $disk = null): ?string
    {
        return null;
    }
}

beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    $this->actingAs((new TestUser)->forceFill(['id' => 17]));
});

function r07ActionStorage(): Drive
{
    return Vaulter::for(DriveTestHelpers::host())->model();
}

it('accepts an upload that fills the quota exactly and rejects one byte over', function (): void {
    $storage = r07ActionStorage();
    $storage->forceFill(['quota_bytes' => 5, 'used_bytes' => 4])->save();
    $base = 'api/vaulter/storages/'.$storage->getKey().'/uploads';

    $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('fits.bin', 'x'),
    ])->assertCreated();

    expect($storage->refresh()->used_bytes)->toBe(5);

    $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('overflow.bin', 'yy'),
    ])->assertStatus(413);

    expect($storage->refresh()->used_bytes)->toBe(5);
});

it('stores null mime when the upload source provides no declared type', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->upload(
        DriveTestHelpers::streamFor('bytes'),
        originalName: 'raw.bin',
        mimeType: null,
        actorId: 17,
    );

    expect($node->mime_type)->toBeNull();

    $activity = Activity::query()->where('verb', EventType::FileUploaded->value)->sole();

    expect($activity->context['mime_type'])->toBeNull()
        ->and($activity->context['size_bytes'])->toBe(5);
});

it('404s download for a folder node before streaming', function (): void {
    $storage = r07ActionStorage();
    $folderId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/folders', ['name' => 'NoDownload'])
        ->assertCreated()
        ->json('data.id');

    $this->get('api/vaulter/nodes/'.$folderId.'/download')->assertNotFound();
});

it('sets Content-Length to the blob size and streams the exact bytes', function (): void {
    $storage = r07ActionStorage();
    $payload = '0123456789';

    $nodeId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/uploads', [
        'file' => UploadedFile::fake()->createWithContent('len.bin', $payload),
    ])->json('data.id');

    app()->instance(StorageDriver::class, new R07StreamingOnlyStorageDriver(app(StorageDriver::class)));

    $response = $this->get('api/vaulter/nodes/'.$nodeId.'/download');

    $response->assertOk();
    expect($response->headers->get('Content-Length'))->toBe((string) strlen($payload))
        ->and($response->streamedContent())->toBe($payload);
});

it('409s copy when the destination already owns the requested name', function (): void {
    $storage = r07ActionStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    $folder = $this->postJson($base.'/folders', ['name' => 'Dest'])->json('data.id');
    $this->postJson($base.'/folders', ['name' => 'Twin', 'parent_id' => $folder])->assertCreated();

    $source = $this->postJson($base.'/uploads', [
        'file' => UploadedFile::fake()->createWithContent('a.txt', 'a'),
        'parent_id' => $folder,
        'name' => 'source.txt',
    ])->assertCreated()->json('data.id');

    $this->postJson('api/vaulter/nodes/'.$source.'/copy', [
        'parent_id' => $folder,
        'name' => 'Twin',
    ])->assertStatus(409)->assertJsonPath('code', 'node_name_conflict')->assertJsonValidationErrors('name');
});

it('deep-copies every live descendant and charges the summed logical bytes', function (): void {
    $drive = DriveTestHelpers::drive();
    $root = $drive->createFolder('Root');
    $child = $drive->createFolder('Child', $root);
    $drive->upload(DriveTestHelpers::streamFor('abc'), into: $child, originalName: 'leaf.bin');
    $used = $drive->model()->refresh()->used_bytes;

    $copy = $drive->copy($root, name: 'Root copy');

    expect($copy->children()->sole()->name)->toBe('Child')
        ->and($copy->children()->sole()->children()->sole()->name)->toBe('leaf.bin')
        ->and($drive->model()->refresh()->used_bytes)->toBe($used + 3);
});

it('rename to the same name emits no rename activity or outbox row', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->createFolder('Stable');

    Activity::query()->delete();
    Outbox::query()->delete();

    $drive->rename($folder, 'Stable', actorId: 17);

    expect(Activity::query()->where('verb', EventType::NodeRenamed->value)->count())->toBe(0)
        ->and(Outbox::query()->where('event', EventType::NodeRenamed->value)->count())->toBe(0);

    $drive->rename($folder, 'Renamed', actorId: 17);

    expect(Activity::query()->where('verb', EventType::NodeRenamed->value)->count())->toBe(1)
        ->and(Outbox::query()->where('event', EventType::NodeRenamed->value)->count())->toBe(1);
});

it('clamps per_page to 1 at the lower bound and 200 at the upper bound', function (): void {
    $storage = r07ActionStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    for ($i = 0; $i < 3; $i++) {
        $this->postJson($base.'/folders', ['name' => 'P'.$i])->assertCreated();
    }

    $this->getJson($base.'/nodes?per_page=0')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson($base.'/nodes?per_page=999')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});
