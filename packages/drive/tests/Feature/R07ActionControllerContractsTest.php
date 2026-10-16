<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Testing\Fixtures\TestUser;

uses(EnablesWebhooksModule::class);

beforeEach(fn () => DriveTestHelpers::insideOperation());

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
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

it('accepts an upload that fills the quota exactly and rejects one byte over', function (): void {
    $storage = r07ActionStorage();
    $storage->forceFill(['quota_bytes' => 5, 'used_bytes' => 4])->save();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey().'/files';

    $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('fits.bin', 'x'),
    ])->assertCreated();

    expect($storage->refresh()->used_bytes)->toBe(5);

    $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('overflow.bin', 'yy'),
    ])->assertStatus(413);

    expect($storage->refresh()->used_bytes)->toBe(5);
});

it('stores detected MIME separately when the upload source provides no declared type', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->actingAs(DriveTestHelpers::actor($drive, 17))->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('bytes'), 'raw.bin'), new UploadOptions(declaredMimeType: null));

    expect($node->mimeType)->toBe('text/plain')->and(DriveTestHelpers::model($node)->file()->firstOrFail()->currentRevision()->firstOrFail()->declared_mime_type)->toBeNull();

    $activity = Activity::query()->where('verb', DriveEventType::FileUploaded->value)->sole();

    expect($activity->context['mime_type'])->toBe('text/plain')
        ->and($activity->context['size_bytes'])->toBe(5);
});

it('returns not_a_file 422 for a visible folder before streaming', function (): void {
    $storage = r07ActionStorage();
    $folderId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/folders', ['name' => 'NoDownload'])
        ->assertCreated()
        ->json('data.id');

    $this->getJson('api/vaulter/v1/files/'.$folderId.'/download')->assertStatus(422)->assertJsonPath('code', 'not_a_file');
});

it('sets Content-Length to the blob size and streams the exact bytes', function (): void {
    $storage = r07ActionStorage();
    $payload = '0123456789';

    $nodeId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/files', [
        'file' => UploadedFile::fake()->createWithContent('len.bin', $payload),
    ])->json('data.id');

    app()->instance(StorageDriver::class, new R07StreamingOnlyStorageDriver(app(StorageDriver::class)));

    $response = $this->get('api/vaulter/v1/files/'.$nodeId.'/download');

    $response->assertOk();
    expect($response->headers->get('Content-Length'))->toBe((string) strlen($payload))
        ->and($response->streamedContent())->toBe($payload);
});

it('rejects explicit Fail copy conflicts atomically and renames default HTTP conflicts', function (): void {
    $storage = r07ActionStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();

    $folder = $this->postJson($base.'/folders', ['name' => 'Dest'])->json('data.id');
    $this->postJson($base.'/folders', ['name' => 'Twin', 'parent_id' => $folder])->assertCreated();

    $source = $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('a.txt', 'a'),
        'parent_id' => $folder,
        'name' => 'source.txt',
    ])->assertCreated()->json('data.id');

    $drive = Vaulter::drive($storage)->asSystem('explicit conflict acceptance');
    $beforeCount = Node::query()->count();
    $beforeBytes = $storage->refresh()->used_bytes;
    expect(fn () => $drive->nodes()->copy($source, $folder, new CopyOptions(name: 'Twin', onConflict: OnNameConflict::Fail)))
        ->toThrow(NodeNameConflictException::class)
        ->and(Node::query()->count())->toBe($beforeCount)
        ->and($storage->refresh()->used_bytes)->toBe($beforeBytes);

    $this->postJson('api/vaulter/v1/nodes/'.$source.'/copy', [
        'destination_id' => $folder,
        'name' => 'Twin',
    ])->assertCreated()->assertJsonPath('data.name', 'Twin (2)');
});

it('deep-copies every live descendant and charges the summed logical bytes', function (): void {
    $drive = DriveTestHelpers::drive();
    $root = $drive->folders()->create('Root');
    $child = $drive->folders()->create('Child', $root);
    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('abc'), 'leaf.bin'), new UploadOptions(parent: $child));
    $used = $drive->model()->refresh()->used_bytes;

    $copy = $drive->nodes()->copy($root, destination: null, options: new CopyOptions(name: 'Root copy'));

    expect(DriveTestHelpers::model($copy)->children()->sole()->name)->toBe('Child')
        ->and(DriveTestHelpers::model($copy)->children()->sole()->children()->sole()->name)->toBe('leaf.bin')
        ->and($drive->model()->refresh()->used_bytes)->toBe($used + 3);
});

it('rename to the same name emits no rename activity or outbox row', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Stable');

    Activity::query()->delete();
    OutboxEvent::query()->delete();

    $drive->actingAs(DriveTestHelpers::actor($drive, 17))->nodes()->rename($folder, 'Stable');

    expect(Activity::query()->where('verb', DriveEventType::NodeRenamed->value)->count())->toBe(0)
        ->and(OutboxEvent::query()->where('type', DriveEventType::NodeRenamed->value)->count())->toBe(0);

    $drive->actingAs(DriveTestHelpers::actor($drive, 17))->nodes()->rename($folder, 'Renamed');

    expect(Activity::query()->where('verb', DriveEventType::NodeRenamed->value)->count())->toBe(1)
        ->and(OutboxEvent::query()->where('type', DriveEventType::NodeRenamed->value)->count())->toBe(1);
});

it('refuses per_page outside 1..200 and serves both bounds', function (): void {
    $storage = r07ActionStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();

    for ($i = 0; $i < 3; $i++) {
        $this->postJson($base.'/folders', ['name' => 'P'.$i])->assertCreated();
    }

    $this->getJson($base.'/nodes?per_page=0')->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
    $this->getJson($base.'/nodes?per_page=201')->assertUnprocessable();
    $this->getJson($base.'/nodes?per_page=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($base.'/nodes?per_page=200')->assertOk()->assertJsonCount(3, 'data');
});
