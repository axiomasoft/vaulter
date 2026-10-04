<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Enums\Activity\ActivityVerb;
use Vaulter\Enums\Webhooks\WebhookEvent;
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
    $drive = DriveTestHelpers::drive();
    $storage = $drive->storage();
    $root = $storage->rootNode()->firstOrFail();

    $storageOutbox = Outbox::query()->where('event', WebhookEvent::StorageCreated->value)->sole();
    $storagePayload = $storageOutbox->payload;

    expect($storageOutbox->panel)->toBe($storage->panel)
        ->and($storageOutbox->topic)->toBe((string) $storage->getKey())
        ->and($storagePayload['event_id'])->toBe($storageOutbox->event_id)
        ->and($storagePayload['actor_id'])->toBeNull();

    unset($storagePayload['event_id'], $storagePayload['actor_id']);
    expect($storagePayload)->toEqual([
        'storage_id' => (string) $storage->getKey(),
        'root_node_id' => (string) $root->getKey(),
        'name' => $storage->name,
    ]);

    $storageActivity = Activity::query()->where('verb', ActivityVerb::StorageCreated->value)->sole();
    expect($storageActivity->context)->toEqual([
        'storage_id' => (string) $storage->getKey(),
        'owner_type' => $storage->owner_type,
        'owner_id' => (string) $storage->owner_id,
        'name' => $storage->name,
    ]);

    $folder = $drive->createFolder('Contract folder', actorId: 17);
    $created = Outbox::query()->where('event', WebhookEvent::NodeCreated->value)->orderByDesc('id')->firstOrFail();
    $createdPayload = $created->payload;
    unset($createdPayload['event_id'], $createdPayload['actor_id']);

    expect($created->topic)->toBe((string) $folder->getKey())
        ->and($createdPayload)->toEqual([
            'node_id' => (string) $folder->getKey(),
            'storage_id' => (string) $storage->getKey(),
            'parent_id' => (string) $root->getKey(),
            'kind' => 'folder',
            'name' => 'Contract folder',
        ]);

    $createdActivity = Activity::query()->where('verb', ActivityVerb::NodeCreated->value)->orderByDesc('id')->firstOrFail();
    expect($createdActivity->target_id)->toEqual($folder->getKey())
        ->and($createdActivity->context)->toEqual([
            'kind' => 'folder',
            'name' => 'Contract folder',
            'parent_id' => (string) $root->getKey(),
        ]);
});

it('returns the complete storage API shape including toolbar abilities', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->storage();

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
    $storage = Vaulter::for(DriveTestHelpers::host())->storage();

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
    $drive = DriveTestHelpers::drive();
    $from = $drive->createFolder('From');
    $to = $drive->createFolder('To');
    $node = $drive->createFolder('Old', $from);

    $drive->move($node, $to, actorId: 17);
    $moved = Outbox::query()->where('event', WebhookEvent::NodeMoved->value)->sole();
    $movePayload = $moved->payload;
    unset($movePayload['event_id'], $movePayload['actor_id']);
    expect($movePayload)->toEqual([
        'node_id' => (string) $node->getKey(),
        'storage_id' => (string) $node->storage_id,
        'from_parent_id' => (string) $from->getKey(),
        'to_parent_id' => (string) $to->getKey(),
    ]);
    expect(Activity::query()->where('verb', ActivityVerb::NodeMoved->value)->sole()->context)->toEqual([
        'from_parent_id' => (string) $from->getKey(),
        'to_parent_id' => (string) $to->getKey(),
    ]);

    $drive->rename($node->refresh(), 'New', actorId: 17);
    $renamed = Outbox::query()->where('event', WebhookEvent::NodeRenamed->value)->sole();
    $renamePayload = $renamed->payload;
    unset($renamePayload['event_id'], $renamePayload['actor_id']);
    expect($renamePayload)->toEqual([
        'node_id' => (string) $node->getKey(),
        'storage_id' => (string) $node->storage_id,
        'from' => 'Old',
        'to' => 'New',
    ]);
    expect(Activity::query()->where('verb', ActivityVerb::NodeRenamed->value)->sole()->context)->toEqual([
        'from' => 'Old',
        'to' => 'New',
    ]);

    $copy = $drive->copy($node->refresh(), $from, name: 'Copied', actorId: 17);
    $copied = Outbox::query()->where('event', WebhookEvent::NodeCopied->value)->sole();
    $copyPayload = $copied->payload;
    unset($copyPayload['event_id'], $copyPayload['actor_id']);
    expect($copied->topic)->toBe((string) $copy->getKey())
        ->and($copyPayload)->toEqual([
            'node_id' => (string) $copy->getKey(),
            'storage_id' => (string) $copy->storage_id,
            'source_id' => (string) $node->getKey(),
            'name' => 'Copied',
        ]);
    expect(Activity::query()->where('verb', ActivityVerb::NodeCopied->value)->sole()->context)->toEqual([
        'source_id' => (string) $node->getKey(),
        'name' => 'Copied',
    ]);
});

it('preserves file upload and version payloads with their actual byte counts', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->upload(
        DriveTestHelpers::streamFor('first bytes'),
        originalName: 'report.txt',
        mimeType: 'text/plain',
        actorId: 17,
    );
    $firstVersion = $node->currentVersion()->firstOrFail();

    $uploaded = Outbox::query()->where('event', WebhookEvent::FileUploaded->value)->sole();
    $uploadPayload = $uploaded->payload;
    unset($uploadPayload['event_id'], $uploadPayload['actor_id']);
    expect($uploadPayload)->toEqual([
        'node_id' => (string) $node->getKey(),
        'storage_id' => (string) $node->storage_id,
        'version_id' => (string) $firstVersion->getKey(),
        'name' => 'report.txt',
        'size_bytes' => 11,
        'mime_type' => 'text/plain',
    ]);
    expect(Activity::query()->where('verb', ActivityVerb::FileUploaded->value)->sole()->context)->toEqual([
        'name' => 'report.txt',
        'size_bytes' => 11,
        'mime_type' => 'text/plain',
        'version_id' => (string) $firstVersion->getKey(),
    ]);

    $drive->uploadVersion($node->refresh(), DriveTestHelpers::streamFor('second version'), actorId: 17);
    $secondVersion = $node->refresh()->currentVersion()->firstOrFail();
    $versioned = Outbox::query()->where('event', WebhookEvent::VersionCreated->value)->sole();
    $versionPayload = $versioned->payload;
    unset($versionPayload['event_id'], $versionPayload['actor_id']);

    expect($versionPayload)->toEqual([
        'node_id' => (string) $node->getKey(),
        'storage_id' => (string) $node->storage_id,
        'version_id' => (string) $secondVersion->getKey(),
        'version_no' => 2,
        'size_bytes' => 14,
    ]);
    expect(Activity::query()->where('verb', ActivityVerb::VersionCreated->value)->sole()->context)->toEqual([
        'version_no' => 2,
        'version_id' => (string) $secondVersion->getKey(),
        'size_bytes' => 14,
    ]);
});

it('preserves trash and restore payloads including actor and destination', function (): void {
    $drive = DriveTestHelpers::drive();
    $parent = $drive->createFolder('Parent');
    $node = $drive->createFolder('Child', $parent);

    $drive->trash($node, actorId: 17);
    $trashed = Outbox::query()->where('event', WebhookEvent::NodeTrashed->value)->sole();
    expect($trashed->panel)->toBe($node->panel)
        ->and($trashed->topic)->toBe((string) $node->getKey())
        ->and($trashed->payload)->toEqual([
            'event_id' => $trashed->event_id,
            'actor_id' => '17',
            'node_id' => (string) $node->getKey(),
            'storage_id' => (string) $node->storage_id,
            'name' => 'Child',
        ]);
    $activity = Activity::query()->where('verb', ActivityVerb::NodeTrashed->value)->sole();
    expect($activity->actor_id)->toEqual(17)
        ->and($activity->target_id)->toEqual($node->getKey())
        ->and($activity->context)->toEqual(['name' => 'Child']);

    $drive->restore($node->refresh(), actorId: 17);
    $restored = Outbox::query()->where('event', WebhookEvent::NodeRestored->value)->sole();
    expect($restored->panel)->toBe($node->panel)
        ->and($restored->topic)->toBe((string) $node->getKey())
        ->and($restored->payload)->toEqual([
            'event_id' => $restored->event_id,
            'actor_id' => '17',
            'node_id' => (string) $node->getKey(),
            'storage_id' => (string) $node->storage_id,
            'parent_id' => (string) $parent->getKey(),
            'name' => 'Child',
        ]);
    $activity = Activity::query()->where('verb', ActivityVerb::NodeRestored->value)->sole();
    expect($activity->actor_id)->toEqual(17)
        ->and($activity->target_id)->toEqual($node->getKey())
        ->and($activity->context)->toEqual([
            'name' => 'Child',
            'parent_id' => (string) $parent->getKey(),
        ]);
});

it('records every force-deleted subtree id and the exact freed bytes', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->createFolder('Deleted');
    $file = $drive->upload(DriveTestHelpers::streamFor('bytes'), into: $folder, originalName: 'f.bin');
    $ids = [(string) $folder->getKey(), (string) $file->getKey()];

    $drive->trash($folder);

    $drive->forceDelete($folder, actorId: 17);

    $outbox = Outbox::query()->where('event', WebhookEvent::NodeForceDeleted->value)->sole();
    $payload = $outbox->payload;
    expect($payload['node_ids'])->toEqualCanonicalizing($ids);
    unset($payload['node_ids']);
    expect($outbox->panel)->toBe('default')
        ->and($outbox->topic)->toBe((string) $drive->storage()->getKey())
        ->and($payload)->toEqual([
            'event_id' => $outbox->event_id,
            'actor_id' => '17',
            'storage_id' => (string) $drive->storage()->getKey(),
            'freed_bytes' => 5,
        ]);
    $activity = Activity::query()->where('verb', ActivityVerb::NodeForceDeleted->value)->sole();
    expect($activity->actor_id)->toEqual(17)
        ->and($activity->storage_id)->toEqual($drive->storage()->getKey())
        ->and($activity->context['node_ids'])->toEqualCanonicalizing($ids)
        ->and(array_keys($activity->context))->toEqualCanonicalizing(['node_ids', 'freed_bytes'])
        ->and($activity->context['freed_bytes'])->toBe(5);
});
