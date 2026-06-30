<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Activity\ActivityRecorder;
use Vaulter\Data\Activity\RecordActivityCommand;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Activity\ActivityVerb;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Events\Drive\NodeCreated;
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

it('writes one storage.created row with the storage scope and root target', function (): void {
    $storage = DriveTestHelpers::drive()->storage();

    $row = ActivityTestHelpers::only(ActivityVerb::StorageCreated->value);

    expect($row->storage_id)->toEqual($storage->getKey())
        ->and($row->target_id)->toEqual($storage->root_node_id)
        ->and($row->context['name'])->toBe($storage->name);
});

it('writes one node.created row carrying the actor and the kind/name context', function (): void {
    $drive = DriveTestHelpers::drive();
    // The storage root already fired a node.created; isolate this folder's row.
    Activity::query()->delete();
    $folder = $drive->createFolder('reports', actorId: 7);

    $row = ActivityTestHelpers::only(ActivityVerb::NodeCreated->value);

    expect($row->target_id)->toEqual($folder->getKey())
        ->and($row->storage_id)->toEqual($folder->storage_id)
        ->and($row->actor_id)->toEqual(7)
        ->and($row->actor_type)->toBe((new TestUser)->getMorphClass())
        ->and($row->context['name'])->toBe('reports')
        ->and($row->context['kind'])->toBe('folder');
});

it('writes one file.uploaded row with size + version context', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->upload(DriveTestHelpers::streamFor('payload'), originalName: 'doc.txt', mimeType: 'text/plain');

    $row = ActivityTestHelpers::only(ActivityVerb::FileUploaded->value);

    expect($row->target_id)->toEqual($node->getKey())
        ->and($row->context['name'])->toBe('doc.txt')
        ->and($row->context['size_bytes'])->toBe(7);
});

it('writes one version.created row when a new version is committed', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->upload(DriveTestHelpers::streamFor('v1'), originalName: 'doc.txt');

    $drive->uploadVersion($node->refresh(), DriveTestHelpers::streamFor('v2-bytes'));

    $row = ActivityTestHelpers::only(ActivityVerb::VersionCreated->value);

    expect($row->target_id)->toEqual($node->getKey())
        ->and($row->context['version_no'])->toBe(2);
});

it('writes one node.moved row with from/to parent context', function (): void {
    $drive = DriveTestHelpers::drive();
    $a = $drive->createFolder('A');
    $b = $drive->createFolder('B');
    $child = $drive->createFolder('child', $a);

    $drive->move($child, $b, actorId: 7);

    $row = ActivityTestHelpers::only(ActivityVerb::NodeMoved->value);

    expect($row->target_id)->toEqual($child->getKey())
        ->and($row->context['from_parent_id'])->toEqual((string) $a->getKey())
        ->and($row->context['to_parent_id'])->toEqual((string) $b->getKey());
});

it('writes one node.renamed row with the old + new name', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->createFolder('old-name');

    $drive->rename($folder, 'new-name', actorId: 7);

    $row = ActivityTestHelpers::only(ActivityVerb::NodeRenamed->value);

    expect($row->context['from'])->toBe('old-name')
        ->and($row->context['to'])->toBe('new-name');
});

it('writes one node.copied row targeting the copy and naming the source', function (): void {
    $drive = DriveTestHelpers::drive();
    $source = $drive->createFolder('source');
    $dest = $drive->createFolder('dest');

    $copy = $drive->copy($source, $dest, name: 'copied');

    $row = ActivityTestHelpers::only(ActivityVerb::NodeCopied->value);

    expect($row->target_id)->toEqual($copy->getKey())
        ->and($row->context['source_id'])->toEqual((string) $source->getKey());
});

it('writes one node.trashed and one node.restored row across the lifecycle', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->createFolder('temp');

    $drive->trash($folder, actorId: 7);
    $drive->restore($folder->refresh(), actorId: 7);

    expect(ActivityTestHelpers::only(ActivityVerb::NodeTrashed->value)->target_id)->toEqual($folder->getKey())
        ->and(ActivityTestHelpers::only(ActivityVerb::NodeRestored->value)->target_id)->toEqual($folder->getKey());
});

it('writes one node.force_deleted row with the freed bytes and ids (no target)', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->upload(DriveTestHelpers::streamFor('to-delete'), originalName: 'gone.txt');
    $drive->trash($node, actorId: 7);

    $drive->forceDelete($node->refresh(), actorId: 7);

    $row = ActivityTestHelpers::only(ActivityVerb::NodeForceDeleted->value);

    expect($row->target_id)->toBeNull()
        ->and($row->storage_id)->toEqual($node->storage_id)
        ->and($row->context['freed_bytes'])->toBeGreaterThan(0)
        ->and($row->context['node_ids'])->toContain((string) $node->getKey());
});

it('writes one grant.created row from the grant event', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'shared');
    $grant = AccessTestHelpers::grant($node, GrantLevel::Writer, scope: GrantScope::Subtree);

    event(new NodeGrantCreated(grant: $grant, actorId: 7));

    $row = ActivityTestHelpers::only(ActivityVerb::GrantCreated->value);

    expect($row->target_id)->toEqual($node->getKey())
        ->and($row->context['level'])->toBe('writer')
        ->and($row->context['effect'])->toBe('allow');
});

it('writes one grant.revoked row from the revoke event', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'locked');
    $subject = new Subject(type: (new TestUser)->getMorphClass(), id: 42);

    event(new NodeGrantRevoked(node: $node, subject: $subject, revoked: 3, actorId: 7));

    $row = ActivityTestHelpers::only(ActivityVerb::GrantRevoked->value);

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

    expect(ActivityTestHelpers::only(ActivityVerb::ShareCreated->value)->target_id)->toEqual($node->getKey())
        ->and(ActivityTestHelpers::only(ActivityVerb::ShareRevoked->value)->context['token_prefix'])
        ->toBe($share->token_prefix);
});

// ─────────────────────────────── anti-fan-out ─────────────────────────────────

it('writes exactly ONE row even when a folder has a deep subtree (no per-node fan-out)', function (): void {
    $drive = DriveTestHelpers::drive();
    $root = $drive->createFolder('root');
    $a = $drive->createFolder('a', $root);
    $drive->createFolder('b', $a);

    Activity::query()->delete();

    // Trashing the whole subtree is ONE event ⇒ ONE row, not one per descendant.
    $drive->trash($root->refresh(), actorId: 7);

    expect(Activity::query()->where('verb', ActivityVerb::NodeTrashed->value)->count())->toBe(1);
});

// ─────────────────────────────── resilience ───────────────────────────────────

it('does NOT break the originating action when the recorder throws', function (): void {
    // A store repository that always fails simulates a broken audit sink.
    $this->app->bind(ActivityStoreRepository::class, fn (): ActivityStoreRepository => new class extends ActivityStoreRepository
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
    $folder = $drive->createFolder('survives', actorId: 7);

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
    $recorder->recordNodeCreated(new NodeCreated(node: $node, actorId: 7));

    $row = Activity::query()->firstOrFail();

    expect($row->ip)->toBeNull()
        ->and($row->user_agent)->toBeNull()
        ->and($row->request_id)->toBeNull();
});
