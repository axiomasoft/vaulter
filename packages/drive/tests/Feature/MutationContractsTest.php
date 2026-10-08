<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Events\Drives\DriveCreated;
use Vaulter\Enums\Events\EventType;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    StorageFacade::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 17]));
});

it('keeps the complete storage and folder event contracts', function (): void {
    $provisioned = null;
    Event::listen(DriveCreated::class, static function (DriveCreated $event) use (&$provisioned): void {
        $provisioned = $event;
    });
    $drive = DriveTestHelpers::drive()->withCorrelationId('mutation-contract');
    $storage = $drive->model();
    $root = $storage->rootNode()->firstOrFail();

    $storageOutbox = Outbox::query()->where('event', EventType::DriveCreated->value)->sole();
    $storagePayload = $storageOutbox->payload;

    expect($storageOutbox->profile)->toBe($storage->profile)
        ->and($storageOutbox->topic)->toBe((string) $storage->getKey())
        ->and($storagePayload['event_id'])->toBe($storageOutbox->event_id)
        ->and($storagePayload['actor_id'])->toBeNull();

    unset($storagePayload['event_id'], $storagePayload['actor_id']);
    expect($storagePayload)->toEqual([
        'drive_id' => (string) $storage->getKey(),
        'root_node_id' => (string) $root->getKey(),
        'name' => $storage->name,
    ]);

    $storageActivity = Activity::query()->where('verb', EventType::DriveCreated->value)->sole();
    expect($provisioned)->toBeInstanceOf(DriveCreated::class)
        ->and($provisioned->correlationId)->toBeString()->not->toBe('');
    expect($storageActivity->context)->toEqual([
        'correlation_id' => $provisioned->correlationId,
        'system_reason' => 'drive provisioning',
        'drive_id' => (string) $storage->getKey(),
        'owner_type' => $storage->owner_type,
        'owner_id' => (string) $storage->owner_id,
        'name' => $storage->name,
    ]);

    $folder = $drive->actingAs(DriveTestHelpers::actor($drive, 17))->folders()->create('Contract folder');
    $created = Outbox::query()->where('event', EventType::FolderCreated->value)->orderByDesc('id')->firstOrFail();
    $createdPayload = $created->payload;
    unset($createdPayload['event_id'], $createdPayload['actor_id']);

    expect($created->topic)->toBe((string) $folder->id)
        ->and($createdPayload)->toEqual([
            'node_id' => (string) $folder->id,
            'drive_id' => (string) $storage->getKey(),
            'parent_id' => (string) $root->getKey(),
            'kind' => 'folder',
            'name' => 'Contract folder',
        ]);

    $createdActivity = Activity::query()->where('verb', EventType::FolderCreated->value)->orderByDesc('id')->firstOrFail();
    expect($createdActivity->target_id)->toEqual($folder->id)
        ->and($createdActivity->context)->toEqual([
            'correlation_id' => 'mutation-contract',
            'kind' => 'folder',
            'name' => 'Contract folder',
            'parent_id' => (string) $root->getKey(),
        ]);
});

it('returns the complete storage API shape including toolbar abilities', function (): void {
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();

    $data = $this->getJson('api/vaulter/storages/'.$storage->getKey())->assertOk()->json('data');
    $keys = array_keys($data);
    sort($keys);

    expect($keys)->toBe([
        'abilities', 'created_at', 'disk', 'id', 'name', 'owner', 'panel', 'quota_bytes',
        'root_node_id', 'slug', 'status', 'storage_type', 'updated_at', 'used_bytes',
    ])
        ->and($data['id'])->toEqual($storage->getKey())
        ->and($data['owner'])->toEqual([
            'type' => $storage->owner_type,
            'id' => $storage->owner_id,
        ])
        ->and($data['root_node_id'])->toEqual($storage->root_node_id)
        ->and($data['used_bytes'])->toBe(0)
        ->and($data['abilities'])->toEqual([
            'view' => true,
            'create_folder' => true,
            'upload' => true,
            'manage' => true,
        ]);
});

it('rejects each malformed resumable upload field', function (array $overrides, string $field): void {
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();

    $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', array_merge([
        'protocol' => 'tus',
        'name' => 'valid.bin',
        'total_size_bytes' => 4,
    ], $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'protocol enum' => [['protocol' => 'unknown'], 'protocol'],
    'name type' => [['name' => 123], 'name'],
    'name length' => [['name' => str_repeat('n', 256)], 'name'],
    'name path separator' => [['name' => 'a/b'], 'name'],
    'size integer' => [['total_size_bytes' => 'half'], 'total_size_bytes'],
    'size nonnegative' => [['total_size_bytes' => -1], 'total_size_bytes'],
    'hash length' => [['expected_sha256' => 'short'], 'expected_sha256'],
    'mime length' => [['mime_type' => str_repeat('m', 192)], 'mime_type'],
    'metadata array' => [['metadata' => 'not an array'], 'metadata'],
]);

it('preserves move, rename and copy payloads and activity context', function (): void {
    $drive = DriveTestHelpers::drive()->withCorrelationId('mutation-contract');
    $from = $drive->folders()->create('From');
    $to = $drive->folders()->create('To');
    $node = $drive->folders()->create('Old', $from);

    $drive->actingAs(DriveTestHelpers::actor($drive, 17))->nodes()->move($node, $to);
    $moved = Outbox::query()->where('event', EventType::NodeMoved->value)->sole();
    $movePayload = $moved->payload;
    unset($movePayload['event_id'], $movePayload['actor_id']);
    expect($movePayload)->toEqual([
        'node_id' => (string) $node->id,
        'drive_id' => (string) $node->driveId,
        'from_parent_id' => (string) $from->id,
        'to_parent_id' => (string) $to->id,
    ]);
    expect(Activity::query()->where('verb', EventType::NodeMoved->value)->sole()->context)->toEqual([
        'correlation_id' => 'mutation-contract',
        'from_parent_id' => (string) $from->id,
        'to_parent_id' => (string) $to->id,
    ]);

    $drive->actingAs(DriveTestHelpers::actor($drive, 17))->nodes()->rename($node, 'New');
    $renamed = Outbox::query()->where('event', EventType::NodeRenamed->value)->sole();
    $renamePayload = $renamed->payload;
    unset($renamePayload['event_id'], $renamePayload['actor_id']);
    expect($renamePayload)->toEqual([
        'node_id' => (string) $node->id,
        'drive_id' => (string) $node->driveId,
        'from' => 'Old',
        'to' => 'New',
    ]);
    expect(Activity::query()->where('verb', EventType::NodeRenamed->value)->sole()->context)->toEqual([
        'correlation_id' => 'mutation-contract',
        'from' => 'Old',
        'to' => 'New',
    ]);

    $copy = $drive->actingAs(DriveTestHelpers::actor($drive, 17))->nodes()->copy($node, $from, options: new CopyOptions(name: 'Copied'));
    $copied = Outbox::query()->where('event', EventType::NodeCopied->value)->sole();
    $copyPayload = $copied->payload;
    unset($copyPayload['event_id'], $copyPayload['actor_id']);
    expect($copied->topic)->toBe((string) $copy->id)
        ->and($copyPayload)->toEqual([
            'node_id' => (string) $copy->id,
            'drive_id' => (string) $copy->driveId,
            'source_id' => (string) $node->id,
            'name' => 'Copied',
        ]);
    expect(Activity::query()->where('verb', EventType::NodeCopied->value)->sole()->context)->toEqual([
        'correlation_id' => 'mutation-contract',
        'source_id' => (string) $node->id,
        'name' => 'Copied',
    ]);
});

it('preserves file upload and version payloads with their actual byte counts', function (): void {
    $drive = DriveTestHelpers::drive()->withCorrelationId('mutation-contract');
    $node = $drive->actingAs(DriveTestHelpers::actor($drive, 17))->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('first bytes'), 'report.txt'), new UploadOptions(declaredMimeType: 'text/plain'));
    $firstVersion = DriveTestHelpers::model($node)->file()->firstOrFail()->currentRevision()->firstOrFail();

    $uploaded = Outbox::query()->where('event', EventType::FileUploaded->value)->sole();
    $uploadPayload = $uploaded->payload;
    unset($uploadPayload['event_id'], $uploadPayload['actor_id']);
    expect($uploadPayload)->toEqual([
        'node_id' => (string) $node->id,
        'drive_id' => (string) $node->driveId,
        'version_id' => (string) $firstVersion->getKey(),
        'name' => 'report.txt',
        'size_bytes' => 11,
        'mime_type' => 'text/plain',
    ]);
    expect(Activity::query()->where('verb', EventType::FileUploaded->value)->sole()->context)->toEqual([
        'correlation_id' => 'mutation-contract',
        'name' => 'report.txt',
        'size_bytes' => 11,
        'mime_type' => 'text/plain',
        'version_id' => (string) $firstVersion->getKey(),
    ]);

    $drive->actingAs(DriveTestHelpers::actor($drive, 17))->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('second version'), DriveTestHelpers::model($node)->name));
    $secondVersion = DriveTestHelpers::model($node)->file()->firstOrFail()->currentRevision()->firstOrFail();
    $versioned = Outbox::query()->where('event', EventType::FileRevisionAdded->value)->sole();
    $versionPayload = $versioned->payload;
    unset($versionPayload['event_id'], $versionPayload['actor_id']);

    expect($versionPayload)->toEqual([
        'node_id' => (string) $node->id,
        'drive_id' => (string) $node->driveId,
        'version_id' => (string) $secondVersion->getKey(),
        'number' => 2,
        'size_bytes' => 14,
    ]);
    expect(Activity::query()->where('verb', EventType::FileRevisionAdded->value)->sole()->context)->toEqual([
        'correlation_id' => 'mutation-contract',
        'number' => 2,
        'version_id' => (string) $secondVersion->getKey(),
        'size_bytes' => 14,
    ]);
});

it('preserves trash and restore payloads including actor and destination', function (): void {
    $drive = DriveTestHelpers::drive()->withCorrelationId('mutation-contract');
    $parent = $drive->folders()->create('Parent');
    $node = $drive->folders()->create('Child', $parent);

    $drive->actingAs(DriveTestHelpers::actor($drive, 17))->nodes()->trash($node);
    $trashed = Outbox::query()->where('event', EventType::NodeTrashed->value)->sole();
    expect($trashed->profile)->toBe(DriveTestHelpers::model($node)->profile)
        ->and($trashed->topic)->toBe((string) $node->id)
        ->and($trashed->payload)->toEqual([
            'event_id' => $trashed->event_id,
            'actor_id' => '17',
            'node_id' => (string) $node->id,
            'drive_id' => (string) $node->driveId,
            'name' => 'Child',
        ]);
    $activity = Activity::query()->where('verb', EventType::NodeTrashed->value)->sole();
    expect($activity->actor_id)->toEqual(17)
        ->and($activity->target_id)->toEqual($node->id)
        ->and($activity->context)->toEqual([
            'correlation_id' => 'mutation-contract',
            'name' => 'Child',
        ]);

    $drive->actingAs(DriveTestHelpers::actor($drive, 17))->trash()->restore($node);
    $restored = Outbox::query()->where('event', EventType::NodeRestored->value)->sole();
    expect($restored->profile)->toBe(DriveTestHelpers::model($node)->profile)
        ->and($restored->topic)->toBe((string) $node->id)
        ->and($restored->payload)->toEqual([
            'event_id' => $restored->event_id,
            'actor_id' => '17',
            'node_id' => (string) $node->id,
            'drive_id' => (string) $node->driveId,
            'parent_id' => (string) $parent->id,
            'name' => 'Child',
        ]);
    $activity = Activity::query()->where('verb', EventType::NodeRestored->value)->sole();
    expect($activity->actor_id)->toEqual(17)
        ->and($activity->target_id)->toEqual($node->id)
        ->and($activity->context)->toEqual([
            'correlation_id' => 'mutation-contract',
            'name' => 'Child',
            'parent_id' => (string) $parent->id,
        ]);
});

it('records every force-deleted subtree id and the exact freed bytes', function (): void {
    $drive = DriveTestHelpers::drive()->withCorrelationId('mutation-contract');
    $folder = $drive->folders()->create('Deleted');
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('bytes'), 'f.bin'), new UploadOptions(parent: $folder));
    $ids = [(string) $folder->id, (string) $file->id];

    $drive->nodes()->trash($folder);

    $drive->actingAs(DriveTestHelpers::actor($drive, 17))->trash()->purge($folder);

    $outbox = Outbox::query()->where('event', EventType::NodePurged->value)->sole();
    $payload = $outbox->payload;
    expect($payload['node_ids'])->toEqualCanonicalizing($ids);
    unset($payload['node_ids']);
    expect($outbox->profile)->toBe('default')
        ->and($outbox->topic)->toBe((string) $drive->model()->getKey())
        ->and($payload)->toEqual([
            'event_id' => $outbox->event_id,
            'actor_id' => '17',
            'drive_id' => (string) $drive->model()->getKey(),
            'freed_bytes' => 5,
        ]);
    $activity = Activity::query()->where('verb', EventType::NodePurged->value)->sole();
    expect($activity->actor_id)->toEqual(17)
        ->and($activity->drive_id)->toEqual($drive->model()->getKey())
        ->and($activity->context['node_ids'])->toEqualCanonicalizing($ids)
        ->and(array_keys($activity->context))->toEqualCanonicalizing(['node_ids', 'freed_bytes', 'correlation_id'])
        ->and($activity->context['freed_bytes'])->toBe(5)
        ->and($activity->context['correlation_id'])->toBe('mutation-contract');
});
