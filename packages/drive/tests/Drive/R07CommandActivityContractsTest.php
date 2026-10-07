<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Events\EventType;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Operations\OperationContext;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
});

it('purge-trash reports zero subtrees when nothing qualifies', function (): void {
    Artisan::call('vaulter:purge-trash');

    expect(Artisan::output())->toContain('Purged 0 trashed subtrees.');
});

it('purge-trash purges on the cutoff day but spares one day newer', function (): void {
    $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
    $storage = AccessTestHelpers::storage();

    $onCutoff = AccessTestHelpers::folder($storage, null, 'on-cutoff');
    $onCutoff->forceFill(['trashed_at' => Carbon::parse('2026-02-09 12:00:00')])->save();

    $newer = AccessTestHelpers::folder($storage, null, 'newer');
    $newer->forceFill(['trashed_at' => Carbon::parse('2026-02-10 00:00:01')])->save();

    Artisan::call('vaulter:purge-trash', ['--days' => '1']);
    $output = Artisan::output();

    expect($output)->toContain('Purged 1 trashed subtree.')
        ->and(Node::query()->whereKey($onCutoff->getKey())->exists())->toBeFalse()
        ->and(Node::query()->whereKey($newer->getKey())->exists())->toBeTrue();
});

it('purge-trash pluralizes multiple purged roots', function (): void {
    $this->travelTo(Carbon::parse('2026-03-01 08:00:00'));
    $storage = AccessTestHelpers::storage();

    foreach (['a', 'b'] as $name) {
        $node = AccessTestHelpers::folder($storage, null, $name);
        $node->forceFill(['trashed_at' => Carbon::now()->subDays(3)])->save();
    }

    Artisan::call('vaulter:purge-trash', ['--days' => '1']);

    expect(Artisan::output())->toContain('Purged 2 trashed subtrees.');
});

it('records sweep counters for the materialised stale batch size', function (): void {
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);
    app()->forgetInstance(MetricsRecorder::class);

    $storage = UploadTestHelpers::storage();
    foreach (['one', 'two'] as $name) {
        $session = UploadTestHelpers::session($storage, targetName: $name.'.bin');
        $session->forceFill(['expires_at' => Carbon::now()->subHour()])->save();
    }

    Artisan::call('vaulter:sweep-uploads');

    $recorder = app(MetricsRecorder::class);
    $recorder->assertHistogram(name: 'vaulter_sweep_batch_rows');
    $recorder->assertCounter(name: 'vaulter_sweep_expired_total');
    $recorder->assertCounter(name: 'vaulter_sweep_skipped_total');

    expect(Artisan::output())->toContain('Swept 2 stale upload sessions.');
});

it('createFolder activity context carries owner and creator ids', function (): void {
    $drive = DriveTestHelpers::drive();
    Activity::query()->delete();

    $folder = $drive->actingAs(DriveTestHelpers::actor($drive, 42))->withCorrelationId('audited-folder')->folders()->create('Audited');

    expect(Activity::query()->where('verb', EventType::FolderCreated->value)->sole()->context)->toEqual([
        'kind' => 'folder',
        'name' => 'Audited',
        'correlation_id' => 'audited-folder',
        'parent_id' => (string) $drive->root()->getKey(),
    ])
        ->and($folder->owner_id)->toEqual(42)
        ->and($folder->created_by)->toEqual(42);
});

it('move within the same parent is a no-op without NodeMoved activity', function (): void {
    $drive = DriveTestHelpers::drive();
    $parent = $drive->folders()->create('Parent');
    $child = $drive->folders()->create('Child', $parent);

    Activity::query()->delete();
    Outbox::query()->delete();

    $drive->actingAs(DriveTestHelpers::actor($drive, 17))->nodes()->move($child, $parent);

    expect(Activity::query()->where('verb', EventType::NodeMoved->value)->count())->toBe(0)
        ->and(Outbox::query()->where('event', EventType::NodeMoved->value)->count())->toBe(0);
});

it('forceDelete on a folder subtree records zero freed bytes and skips quota adjustment', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Empty tree');
    $used = $drive->model()->refresh()->used_bytes;

    $drive->nodes()->trash($folder);

    $drive->actingAs(DriveTestHelpers::actor($drive, 17))->withCorrelationId('audited-purge')->trash()->purge($folder);

    expect($drive->model()->refresh()->used_bytes)->toBe($used)
        ->and(Activity::query()->where('verb', EventType::NodePurged->value)->sole()->context)->toEqual([
            'node_ids' => [(string) $folder->getKey()],
            'freed_bytes' => 0,
            'correlation_id' => 'audited-purge',
        ]);
});

it('restore re-homes to the storage root when the original parent was trashed', function (): void {
    $drive = DriveTestHelpers::drive();
    $parent = $drive->folders()->create('Parent');
    $child = $drive->folders()->create('Child', $parent);

    $drive->nodes()->trash($child);
    $drive->nodes()->trash($parent);

    $restored = $drive->actingAs(DriveTestHelpers::actor($drive, 17))->withCorrelationId('audited-restore')->trash()->restore($child->refresh());

    expect($restored->parent_id)->toEqual($drive->model()->root_node_id)
        ->and(Activity::query()->where('verb', EventType::NodeRestored->value)->orderByDesc('id')->firstOrFail()->context)->toEqual([
            'name' => 'Child',
            'correlation_id' => 'audited-restore',
            'parent_id' => (string) $drive->model()->root_node_id,
        ]);
});

it('upload session initiated activity carries the exact lifecycle context', function (): void {
    $drive = DriveTestHelpers::drive();
    Activity::query()->delete();

    $session = app(UploadSessionManager::class)->initiate(context: OperationContext::system($drive->model(), 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetParent: $drive->root(),
        targetName: 'ctx.bin',
        totalSizeBytes: 8,
        mimeType: null,
    ));

    $row = Activity::query()->where('verb', EventType::UploadInitiated->value)->sole();

    expect($row->context)->toEqual([
        'upload_id' => $session->upload_id,
        'protocol' => 'tus',
        'target_name' => 'ctx.bin',
    ]);
});

it('force-delete outbox occurrence ids are distinct ULIDs', function (): void {
    $drive = DriveTestHelpers::drive();
    Outbox::query()->delete();

    $first = $drive->folders()->create('One');
    $drive->nodes()->trash($first);
    $drive->actingAs(DriveTestHelpers::actor($drive, 9))->trash()->purge($first);
    $firstKey = Outbox::query()->where('event', EventType::NodePurged->value)->sole()->event_id;

    $second = $drive->folders()->create('Two');
    $drive->nodes()->trash($second);
    $drive->actingAs(DriveTestHelpers::actor($drive, 9))->trash()->purge($second);
    $secondKey = Outbox::query()->where('event', EventType::NodePurged->value)->orderByDesc('id')->firstOrFail()->event_id;

    expect($firstKey)->not->toBe($secondKey)
        ->and(Str::isUlid($firstKey))->toBeTrue()
        ->and(Str::isUlid($secondKey))->toBeTrue();
});

it('DriveContext upload from a string uses the provided original name for the version row', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->actingAs(DriveTestHelpers::actor($drive, 3))->files()->upload(UploadSource::fromString('plain-bytes', 'from-string.txt'), new UploadOptions(declaredMimeType: 'text/plain'));

    expect($node->name)->toBe('from-string.txt')
        ->and($node->file()->firstOrFail()->currentRevision()->firstOrFail()->original_name)->toBe('from-string.txt');
});

it('StorageManager root() provisions a missing root with an empty name', function (): void {
    $drive = DriveTestHelpers::drive();
    $storage = $drive->model();
    $oldRootId = $storage->root_node_id;

    // The root row must be gone for good: even a soft-deleted parentless row is the
    // drive's one parentless node under the single-root index.
    $storage->forceFill(['root_node_id' => null])->save();
    Node::query()->whereKey($oldRootId)->delete();

    $root = app(DriveProvisioner::class)->root($storage->refresh());

    expect($root->parent_id)->toBeNull()
        ->and($root->name)->toBe('')
        ->and($storage->refresh()->root_node_id)->toEqual($root->getKey());
});
