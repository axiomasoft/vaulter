<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Activity\ActivityRecorder;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Activity\RecordActivityCommand;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Events\Sharing\NodeShareCreated;
use Vaulter\Events\Sharing\NodeShareRevoked;
use Vaulter\Models\Activity\Activity;
use Vaulter\Repositories\Activity\ActivityStoreRepository;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Activity\ActivityTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\Sharing\ShareTestHelpers;

/**
 * The append-only activity feed (§2.9 / M2c-activity). Each Drive domain event must
 * produce EXACTLY ONE activity row with the right verb/actor/target/context —
 * recipients resolved at READ, never a per-affected-user fan-out (§1.5). Recording
 * is gated on the 'activity' feature and is resilient: a recorder failure never
 * breaks the originating action.
 */
beforeEach(function (): void {
    // The recorder runs; the base suite is open (null driver) so Drive ops succeed.
    config()->set('vaulter.features.share_links', true);
    $this->actingAs((new TestUser)->forceFill(['id' => 7]));
});

// ─────────────────────────────── one event ⇒ one row ──────────────────────────

it('writes one drive.created row with the storage scope and root target', function (): void {
    $storage = DriveTestHelpers::drive()->model();

    $row = ActivityTestHelpers::only(EventType::DriveCreated->value);

    expect($row->drive_id)->toEqual($storage->getKey())
        ->and($row->target_id)->toEqual($storage->root_node_id)
        ->and($row->context['name'])->toBe($storage->name);
});

it('writes one folder.created row carrying the actor and the kind/name context', function (): void {
    $drive = DriveTestHelpers::drive();
    // The storage root already fired a folder.created; isolate this folder's row.
    Activity::query()->delete();
    $folder = $drive->actingAs(DriveTestHelpers::actor($drive, 7))->folders()->create('reports');

    $row = ActivityTestHelpers::only(EventType::FolderCreated->value);

    expect($row->target_id)->toEqual($folder->getKey())
        ->and($row->drive_id)->toEqual($folder->drive_id)
        ->and($row->actor_id)->toEqual(7)
        ->and($row->actor_type)->toBe((new TestUser)->getMorphClass())
        ->and($row->context['name'])->toBe('reports')
        ->and($row->context['kind'])->toBe('folder');
});

it('writes one file.uploaded row with size + version context', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('payload'), 'doc.txt'), new UploadOptions(declaredMimeType: 'text/plain'));

    $row = ActivityTestHelpers::only(EventType::FileUploaded->value);

    expect($row->target_id)->toEqual($node->getKey())
        ->and($row->context['name'])->toBe('doc.txt')
        ->and($row->context['size_bytes'])->toBe(7);
});

it('writes one file.revision_added row when a new version is committed', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('v1'), 'doc.txt'));

    $drive->files()->addRevision($node->refresh(), UploadSource::fromStream(DriveTestHelpers::streamFor('v2-bytes'), $node->refresh()->name));

    $row = ActivityTestHelpers::only(EventType::FileRevisionAdded->value);

    expect($row->target_id)->toEqual($node->getKey())
        ->and($row->context['number'])->toBe(2);
});

it('writes one node.moved row with from/to parent context', function (): void {
    $drive = DriveTestHelpers::drive();
    $a = $drive->folders()->create('A');
    $b = $drive->folders()->create('B');
    $child = $drive->folders()->create('child', $a);

    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->nodes()->move($child, $b);

    $row = ActivityTestHelpers::only(EventType::NodeMoved->value);

    expect($row->target_id)->toEqual($child->getKey())
        ->and($row->context['from_parent_id'])->toEqual((string) $a->getKey())
        ->and($row->context['to_parent_id'])->toEqual((string) $b->getKey());
});

it('writes one node.renamed row with the old + new name', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('old-name');

    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->nodes()->rename($folder, 'new-name');

    $row = ActivityTestHelpers::only(EventType::NodeRenamed->value);

    expect($row->context['from'])->toBe('old-name')
        ->and($row->context['to'])->toBe('new-name');
});

it('writes one node.copied row targeting the copy and naming the source', function (): void {
    $drive = DriveTestHelpers::drive();
    $source = $drive->folders()->create('source');
    $dest = $drive->folders()->create('dest');

    $copy = $drive->nodes()->copy($source, $dest, options: new CopyOptions(name: 'copied'));

    $row = ActivityTestHelpers::only(EventType::NodeCopied->value);

    expect($row->target_id)->toEqual($copy->getKey())
        ->and($row->context['source_id'])->toEqual((string) $source->getKey());
});

it('writes one node.trashed and one node.restored row across the lifecycle', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('temp');

    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->nodes()->trash($folder);
    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->trash()->restore($folder->refresh());

    expect(ActivityTestHelpers::only(EventType::NodeTrashed->value)->target_id)->toEqual($folder->getKey())
        ->and(ActivityTestHelpers::only(EventType::NodeRestored->value)->target_id)->toEqual($folder->getKey());
});

it('writes one node.purged row with the freed bytes and ids (no target)', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('to-delete'), 'gone.txt'));
    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->nodes()->trash($node);

    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->trash()->purge($node->refresh());

    $row = ActivityTestHelpers::only(EventType::NodePurged->value);

    expect($row->target_id)->toBeNull()
        ->and($row->drive_id)->toEqual($node->drive_id)
        ->and($row->context['freed_bytes'])->toBeGreaterThan(0)
        ->and($row->context['node_ids'])->toContain((string) $node->getKey());
});

it('writes one grant.created row from the grant event', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'shared');
    $grant = AccessTestHelpers::grant($node, RoleRegistry::WRITER, scope: GrantScope::Subtree);

    event(new NodeGrantCreated(grant: $grant, actorId: 7));

    $row = ActivityTestHelpers::only(EventType::GrantCreated->value);

    expect($row->target_id)->toEqual($node->getKey())
        ->and($row->context['role'])->toBe('writer')
        ->and($row->context['role_version'])->toBe(1)
        ->and($row->context)->not->toHaveKeys(['level', 'effect']);
});

it('writes one grant.revoked row from the revoke event', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'locked');
    $subject = new Subject(type: (new TestUser)->getMorphClass(), id: 42);

    event(new NodeGrantRevoked(node: $node, subject: $subject, revoked: 3, actorId: 7));

    $row = ActivityTestHelpers::only(EventType::GrantRevoked->value);

    expect($row->target_id)->toEqual($node->getKey())
        ->and($row->context['revoked'])->toBe(3)
        ->and($row->context['subject_id'])->toBe('42');
});

it('writes one share.created and one share.revoked row from the share events', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'public');
    $share = ShareTestHelpers::share($node, token: 'tok-activity');

    event(new NodeShareCreated(share: $share, actorId: 7));
    event(new NodeShareRevoked(share: $share, actorId: 7));

    expect(ActivityTestHelpers::only(EventType::ShareCreated->value)->target_id)->toEqual($node->getKey())
        ->and(ActivityTestHelpers::only(EventType::ShareRevoked->value)->context['token_prefix'])
        ->toBe($share->token_prefix);
});

// ─────────────────────────────── anti-fan-out ─────────────────────────────────

it('writes exactly ONE row even when a folder has a deep subtree (no per-node fan-out)', function (): void {
    $drive = DriveTestHelpers::drive();
    $root = $drive->folders()->create('root');
    $a = $drive->folders()->create('a', $root);
    $drive->folders()->create('b', $a);

    Activity::query()->delete();

    // Trashing the whole subtree is ONE event ⇒ ONE row, not one per descendant.
    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->nodes()->trash($root->refresh());

    expect(Activity::query()->where('verb', EventType::NodeTrashed->value)->count())->toBe(1);
});

// ─────────────────────────────── resilience ───────────────────────────────────

it('does NOT break the originating action when the recorder throws', function (): void {
    // A store repository that always fails simulates a broken audit sink.
    $this->app->bind(ActivityStoreRepository::class, fn (): ActivityStoreRepository => new class(app(VaulterConfig::class)) extends ActivityStoreRepository
    {
        #[Override]
        public function record(RecordActivityCommand $command): Activity
        {
            throw new RuntimeException('audit sink is down');
        }
    });

    Log::spy();

    $drive = DriveTestHelpers::drive();

    // The folder is created and returned even though recording failed.
    $folder = $drive->actingAs(DriveTestHelpers::actor($drive, 7))->folders()->create('survives');

    expect($folder->exists)->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')->atLeast()->once();
});

// ─────────────────────────────── console/queue degrade ────────────────────────

it('degrades to null ip/user-agent/request-id off the HTTP lifecycle', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'console-node');

    // Simulate a console/queue context: no request bound, exactly as RequestContext
    // sees off the HTTP lifecycle. The recorder must not require one.
    app()->forgetInstance('request');
    app()->offsetUnset('request');

    $recorder = app(ActivityRecorder::class);

    Activity::query()->delete();
    $recorder->recordForNode(verb: EventType::FolderCreated, node: $node, actorId: 7);

    $row = Activity::query()->firstOrFail();

    expect($row->ip)->toBeNull()
        ->and($row->user_agent)->toBeNull()
        ->and($row->request_id)->toBeNull();
});
