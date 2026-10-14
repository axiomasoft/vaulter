<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Events\Drives\DriveCreated;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

uses(EnablesWebhooksModule::class);

beforeEach(function (): void {
    StorageFacade::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 17]));
});

/**
 * The outbox payload is the webhook envelope of the row's occurrence; returns its
 * subject, data and actor. Names and sizes stay in the activity context.
 *
 * @return array{subject: array<string, mixed>, data: array<string, mixed>, actor: array<string, mixed>}
 */
function mutationEnvelope(Outbox $row): array
{
    $payload = $row->payload;
    expect($payload['id'])->toBe($row->event_id)
        ->and($payload['type'])->toBe($row->event)
        ->and($payload['correlation_id'])->toBeString()->not->toBe('');

    return ['subject' => $payload['subject'], 'data' => $payload['data'], 'actor' => $payload['actor']];
}

it('keeps the complete storage and folder event contracts', function (): void {
    $provisioned = null;
    Event::listen(DriveCreated::class, static function (DriveCreated $event) use (&$provisioned): void {
        $provisioned = $event;
    });
    $drive = DriveTestHelpers::drive()->withCorrelationId('mutation-contract');
    $storage = $drive->model();
    $root = $storage->rootNode()->firstOrFail();

    $storageOutbox = Outbox::query()->where('event', DriveEventType::DriveCreated->value)->sole();

    expect($storageOutbox->profile)->toBe($storage->profile)
        ->and($storageOutbox->topic)->toBe((string) $storage->getKey())
        ->and(mutationEnvelope($storageOutbox))->toEqual([
            'subject' => ['type' => 'drive', 'id' => (string) $storage->getKey()],
            'data' => ['root_node_id' => (string) $root->getKey(), 'profile' => $storage->profile],
            'actor' => ['type' => 'vaulter:system', 'id' => null],
        ]);

    $storageActivity = Activity::query()->where('verb', DriveEventType::DriveCreated->value)->sole();
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
    $created = Outbox::query()->where('event', DriveEventType::FolderCreated->value)->orderByDesc('id')->firstOrFail();

    expect($created->topic)->toBe((string) $folder->id)
        ->and(mutationEnvelope($created))->toEqual([
            'subject' => ['type' => 'node', 'id' => (string) $folder->id],
            'data' => ['parent_id' => (string) $root->getKey()],
            'actor' => ['type' => (new TestUser)->getMorphClass(), 'id' => '17'],
        ])
        ->and($created->payload['drive_id'])->toBe((string) $storage->getKey());

    $createdActivity = Activity::query()->where('verb', DriveEventType::FolderCreated->value)->orderByDesc('id')->firstOrFail();
    expect($createdActivity->target_id)->toEqual($folder->id)
        ->and($createdActivity->context)->toEqual([
            'correlation_id' => 'mutation-contract',
            'kind' => 'folder',
            'name' => 'Contract folder',
            'parent_id' => (string) $root->getKey(),
        ]);
});

it('returns the complete v1 drive shape with string ids and nothing internal', function (): void {
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();

    $data = $this->getJson('api/vaulter/v1/drives/'.$storage->getKey())->assertOk()->json('data');
    $keys = array_keys($data);
    sort($keys);

    expect($keys)->toBe([
        'created_at', 'id', 'name', 'owner', 'profile', 'quota_bytes', 'root_node_id', 'status', 'used_bytes',
    ])
        ->and($data['id'])->toBe((string) $storage->getKey())
        ->and($data['owner'])->toBe([
            'type' => $storage->owner_type,
            'id' => (string) $storage->owner_id,
        ])
        ->and($data['root_node_id'])->toBe((string) $storage->root_node_id)
        ->and($data['used_bytes'])->toBe(0);
});

it('rejects each malformed resumable upload field', function (array $overrides, string $field): void {
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();

    $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', array_merge([
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
    $moved = Outbox::query()->where('event', DriveEventType::NodeMoved->value)->sole();
    expect(mutationEnvelope($moved)['data'])->toEqual([
        'from_parent_id' => (string) $from->id,
        'to_parent_id' => (string) $to->id,
    ])->and($moved->payload['drive_id'])->toBe((string) $node->driveId);
    expect(Activity::query()->where('verb', DriveEventType::NodeMoved->value)->sole()->context)->toEqual([
        'correlation_id' => 'mutation-contract',
        'from_parent_id' => (string) $from->id,
        'to_parent_id' => (string) $to->id,
    ]);

    $drive->actingAs(DriveTestHelpers::actor($drive, 17))->nodes()->rename($node, 'New');
    $renamed = Outbox::query()->where('event', DriveEventType::NodeRenamed->value)->sole();
    // The names travel only in the activity context.
    expect(mutationEnvelope($renamed)['data'])->toBe([])
        ->and($renamed->payload['subject'])->toBe(['type' => 'node', 'id' => (string) $node->id]);
    expect(Activity::query()->where('verb', DriveEventType::NodeRenamed->value)->sole()->context)->toEqual([
        'correlation_id' => 'mutation-contract',
        'from' => 'Old',
        'to' => 'New',
    ]);

    $copy = $drive->actingAs(DriveTestHelpers::actor($drive, 17))->nodes()->copy($node, $from, options: new CopyOptions(name: 'Copied'));
    $copied = Outbox::query()->where('event', DriveEventType::NodeCopied->value)->sole();
    expect($copied->topic)->toBe((string) $copy->id)
        ->and(mutationEnvelope($copied))->toMatchArray([
            'subject' => ['type' => 'node', 'id' => (string) $copy->id],
            'data' => ['source_id' => (string) $node->id, 'destination_id' => (string) $from->id],
        ]);
    expect(Activity::query()->where('verb', DriveEventType::NodeCopied->value)->sole()->context)->toEqual([
        'correlation_id' => 'mutation-contract',
        'source_id' => (string) $node->id,
        'name' => 'Copied',
    ]);
});

it('preserves file upload and version payloads with their actual byte counts', function (): void {
    $drive = DriveTestHelpers::drive()->withCorrelationId('mutation-contract');
    $node = $drive->actingAs(DriveTestHelpers::actor($drive, 17))->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('first bytes'), 'report.txt'), new UploadOptions(declaredMimeType: 'text/plain'));
    $firstVersion = DriveTestHelpers::model($node)->file()->firstOrFail()->currentRevision()->firstOrFail();

    $uploaded = Outbox::query()->where('event', DriveEventType::FileUploaded->value)->sole();
    expect(mutationEnvelope($uploaded)['data'])->toEqual([
        'parent_id' => (string) DriveTestHelpers::model($node)->parent_id,
        'revision_id' => (string) $firstVersion->getKey(),
        'size' => 11,
    ]);
    expect(Activity::query()->where('verb', DriveEventType::FileUploaded->value)->sole()->context)->toEqual([
        'correlation_id' => 'mutation-contract',
        'name' => 'report.txt',
        'size_bytes' => 11,
        'mime_type' => 'text/plain',
        'version_id' => (string) $firstVersion->getKey(),
    ]);

    $drive->actingAs(DriveTestHelpers::actor($drive, 17))->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('second version'), DriveTestHelpers::model($node)->name));
    $secondVersion = DriveTestHelpers::model($node)->file()->firstOrFail()->currentRevision()->firstOrFail();
    $versioned = Outbox::query()->where('event', DriveEventType::FileRevisionAdded->value)->sole();

    expect(mutationEnvelope($versioned)['data'])->toEqual([
        'revision_id' => (string) $secondVersion->getKey(),
        'number' => 2,
        'size' => 14,
    ]);
    expect(Activity::query()->where('verb', DriveEventType::FileRevisionAdded->value)->sole()->context)->toEqual([
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
    $trashed = Outbox::query()->where('event', DriveEventType::NodeTrashed->value)->sole();
    expect($trashed->profile)->toBe(DriveTestHelpers::model($node)->profile)
        ->and($trashed->topic)->toBe((string) $node->id)
        ->and(mutationEnvelope($trashed))->toEqual([
            'subject' => ['type' => 'node', 'id' => (string) $node->id],
            'data' => [],
            'actor' => ['type' => (new TestUser)->getMorphClass(), 'id' => '17'],
        ]);
    $activity = Activity::query()->where('verb', DriveEventType::NodeTrashed->value)->sole();
    expect($activity->actor_id)->toEqual(17)
        ->and($activity->target_id)->toEqual($node->id)
        ->and($activity->context)->toEqual([
            'correlation_id' => 'mutation-contract',
            'name' => 'Child',
        ]);

    $drive->actingAs(DriveTestHelpers::actor($drive, 17))->trash()->restore($node);
    $restored = Outbox::query()->where('event', DriveEventType::NodeRestored->value)->sole();
    expect($restored->profile)->toBe(DriveTestHelpers::model($node)->profile)
        ->and($restored->topic)->toBe((string) $node->id)
        ->and(mutationEnvelope($restored))->toEqual([
            'subject' => ['type' => 'node', 'id' => (string) $node->id],
            'data' => ['parent_id' => (string) $parent->id],
            'actor' => ['type' => (new TestUser)->getMorphClass(), 'id' => '17'],
        ]);
    $activity = Activity::query()->where('verb', DriveEventType::NodeRestored->value)->sole();
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

    $outbox = Outbox::query()->where('event', DriveEventType::NodePurged->value)->sole();
    $envelope = mutationEnvelope($outbox);
    expect($envelope['data']['node_ids'])->toEqualCanonicalizing($ids);
    unset($envelope['data']['node_ids']);
    expect($outbox->profile)->toBe($drive->model()->profile)
        ->and($outbox->topic)->toBe((string) $drive->model()->getKey())
        ->and($envelope)->toEqual([
            'subject' => ['type' => 'drive', 'id' => (string) $drive->model()->getKey()],
            'data' => ['node_count' => 2, 'freed_bytes' => 5],
            'actor' => ['type' => (new TestUser)->getMorphClass(), 'id' => '17'],
        ]);
    $activity = Activity::query()->where('verb', DriveEventType::NodePurged->value)->sole();
    expect($activity->actor_id)->toEqual(17)
        ->and($activity->drive_id)->toEqual($drive->model()->getKey())
        ->and($activity->context['node_ids'])->toEqualCanonicalizing($ids)
        ->and(array_keys($activity->context))->toEqualCanonicalizing(['node_ids', 'freed_bytes', 'correlation_id'])
        ->and($activity->context['freed_bytes'])->toBe(5)
        ->and($activity->context['correlation_id'])->toBe('mutation-contract');
});
