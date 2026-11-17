<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Activity\ActivityRecorder;
use Vaulter\Drive\Changes\ChangeJournalSink;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Data\Activity\RecordActivityCommand;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\Snapshots\Mapping\AccessSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\Access\NodeGrantCreated;
use Vaulter\Drive\Events\Access\NodeGrantRevoked;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Events\Nodes\FolderCreated;
use Vaulter\Drive\Events\Sharing\NodeShareCreated;
use Vaulter\Drive\Events\Sharing\NodeShareRevoked;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Repositories\Activity\ActivityStoreRepository;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Activity\ActivityTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Sharing\ShareTestHelpers;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventRecorder;
use Vaulter\Events\EventTypeContract;
use Vaulter\Events\SinkResult;
use Vaulter\Events\SinkWriter;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * The append-only activity feed. Each Drive domain event must
 * produce EXACTLY ONE activity row with the right verb/actor/target/context —
 * recipients resolved at READ, never a per-affected-user fan-out. Recording
 * is gated on the 'activity' feature and atomic: a failed write rolls the
 * originating action back.
 */
beforeEach(function (): void {
    // The recorder runs; the base suite is open (null driver) so Drive ops succeed.
    config()->set('vaulter-drive.features.share_links', true);
    $this->actingAs((new TestUser)->forceFill(['id' => 7]));
});

/** Record an event the way an Action does: inside a Vaulter transaction. */
function recordActivityEvent(DomainEvent $event): void
{
    app(VaulterDatabase::class)->transaction(fn () => app(EventRecorder::class)->record($event));
}

function recordActivityActor(): ActorRef
{
    return new ActorRef(type: (new TestUser)->getMorphClass(), id: 7);
}

// ─────────────────────────────── one event ⇒ one row ──────────────────────────

it('writes one drive.created row with the storage scope and root target', function (): void {
    $storage = DriveTestHelpers::drive()->model();

    $row = ActivityTestHelpers::only(DriveEventType::DriveCreated->value);

    expect($row->drive_id)->toEqual($storage->getKey())
        ->and($row->target_id)->toEqual($storage->root_node_id)
        ->and($row->context['name'])->toBe($storage->name);
});

it('writes one folder.created row carrying the actor and the kind/name context', function (): void {
    $drive = DriveTestHelpers::drive();
    // The storage root already fired a folder.created; isolate this folder's row.
    Activity::query()->delete();
    $folder = $drive->actingAs(DriveTestHelpers::actor($drive, 7))->folders()->create('reports');

    $row = ActivityTestHelpers::only(DriveEventType::FolderCreated->value);

    expect($row->target_id)->toEqual($folder->id)
        ->and($row->drive_id)->toEqual($folder->driveId)
        ->and($row->actor_id)->toEqual(7)
        ->and($row->actor_type)->toBe((new TestUser)->getMorphClass())
        ->and($row->context['name'])->toBe('reports')
        ->and($row->context['kind'])->toBe('folder');
});

it('writes one file.uploaded row with size + version context', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('payload'), 'doc.txt'), new UploadOptions(declaredMimeType: 'text/plain'));

    $row = ActivityTestHelpers::only(DriveEventType::FileUploaded->value);

    expect($row->target_id)->toEqual($node->id)
        ->and($row->context['name'])->toBe('doc.txt')
        ->and($row->context['size_bytes'])->toBe(7);
});

it('writes one file.revision_added row when a new version is committed', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('v1'), 'doc.txt'));

    $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('v2-bytes'), $node->name));

    $row = ActivityTestHelpers::only(DriveEventType::FileRevisionAdded->value);

    expect($row->target_id)->toEqual($node->id)
        ->and($row->context['number'])->toBe(2);
});

it('writes one node.moved row with from/to parent context', function (): void {
    $drive = DriveTestHelpers::drive();
    $a = $drive->folders()->create('A');
    $b = $drive->folders()->create('B');
    $child = $drive->folders()->create('child', $a);

    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->nodes()->move($child, $b);

    $row = ActivityTestHelpers::only(DriveEventType::NodeMoved->value);

    expect($row->target_id)->toEqual($child->id)
        ->and($row->context['from_parent_id'])->toEqual($a->id)
        ->and($row->context['to_parent_id'])->toEqual($b->id);
});

it('writes one node.renamed row with the old + new name', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('old-name');

    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->nodes()->rename($folder, 'new-name');

    $row = ActivityTestHelpers::only(DriveEventType::NodeRenamed->value);

    expect($row->context['from'])->toBe('old-name')
        ->and($row->context['to'])->toBe('new-name');
});

it('writes one node.copied row targeting the copy and naming the source', function (): void {
    $drive = DriveTestHelpers::drive();
    $source = $drive->folders()->create('source');
    $dest = $drive->folders()->create('dest');

    $copy = $drive->nodes()->copy($source, $dest, options: new CopyOptions(name: 'copied'));

    $row = ActivityTestHelpers::only(DriveEventType::NodeCopied->value);

    expect($row->target_id)->toEqual($copy->id)
        ->and($row->context['source_id'])->toEqual($source->id);
});

it('writes one node.trashed and one node.restored row across the lifecycle', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('temp');

    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->nodes()->trash($folder);
    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->trash()->restore($folder);

    expect(ActivityTestHelpers::only(DriveEventType::NodeTrashed->value)->target_id)->toEqual($folder->id)
        ->and(ActivityTestHelpers::only(DriveEventType::NodeRestored->value)->target_id)->toEqual($folder->id);
});

it('writes one node.purged row with the freed bytes and ids (no target)', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('to-delete'), 'gone.txt'));
    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->nodes()->trash($node);

    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->trash()->purge($node);

    $row = ActivityTestHelpers::only(DriveEventType::NodePurged->value);

    expect($row->target_id)->toBeNull()
        ->and($row->drive_id)->toEqual($node->driveId)
        ->and($row->context['freed_bytes'])->toBeGreaterThan(0)
        ->and($row->context['node_ids'])->toContain($node->id);
});

it('writes one grant.created row from the grant event', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'shared');
    $grant = AccessTestHelpers::grant($node, RoleRegistry::WRITER, scope: GrantScope::Subtree);

    recordActivityEvent(new NodeGrantCreated(accessSnapshots: app(AccessSnapshotMapper::class), grant: $grant, actor: recordActivityActor(), correlationId: (string) Str::ulid()));

    $row = ActivityTestHelpers::only(DriveEventType::GrantCreated->value);

    expect($row->target_id)->toEqual($node->id)
        ->and($row->context['role'])->toBe('writer')
        ->and($row->context['role_version'])->toBe(1)
        ->and($row->context)->not->toHaveKeys(['level', 'effect']);
});

it('writes one grant.revoked row from the revoke event', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'locked');
    $subject = new Subject(type: (new TestUser)->getMorphClass(), id: 42);

    recordActivityEvent(new NodeGrantRevoked(nodeSnapshots: app(NodeSnapshotMapper::class), node: $node, subject: $subject, revoked: 3, actor: recordActivityActor(), correlationId: (string) Str::ulid()));

    $row = ActivityTestHelpers::only(DriveEventType::GrantRevoked->value);

    expect($row->target_id)->toEqual($node->id)
        ->and($row->context['revoked'])->toBe(3)
        ->and($row->context['subject_id'])->toBe('42');
});

it('writes one share.created and one share.revoked row from the share events', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'public');
    $share = ShareTestHelpers::share($node, token: 'tok-activity');

    recordActivityEvent(new NodeShareCreated(accessSnapshots: app(AccessSnapshotMapper::class), share: $share, actor: recordActivityActor(), correlationId: (string) Str::ulid()));
    recordActivityEvent(new NodeShareRevoked(accessSnapshots: app(AccessSnapshotMapper::class), share: $share, actor: recordActivityActor(), correlationId: (string) Str::ulid()));

    expect(ActivityTestHelpers::only(DriveEventType::ShareCreated->value)->target_id)->toEqual($node->id)
        ->and(ActivityTestHelpers::only(DriveEventType::ShareRevoked->value)->context['token_prefix'])
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
    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->nodes()->trash($root);

    expect(Activity::query()->where('verb', DriveEventType::NodeTrashed->value)->count())->toBe(1);
});

// ─────────────────────────────── resilience ───────────────────────────────────

it('rolls the originating action back when the activity write fails', function (): void {
    $drive = DriveTestHelpers::drive();
    $actor = DriveTestHelpers::actor($drive, 7);

    // A store repository that always fails simulates a broken audit sink.
    $this->app->bind(ActivityStoreRepository::class, fn (): ActivityStoreRepository => new class(app(DriveOptions::class), app(VaulterDatabase::class), app(IdStrategyResolver::class)) extends ActivityStoreRepository
    {
        #[Override]
        public function record(RecordActivityCommand $command): bool
        {
            throw new RuntimeException('audit sink is down');
        }
    });

    expect(fn (): NodeSnapshot => $drive->actingAs($actor)->folders()->create('lost'))->toThrow(RuntimeException::class, 'audit sink is down')
        ->and(DriveTestHelpers::model($drive->root()->id)->children()->where('name', 'lost')->exists())->toBeFalse()
        ->and(Activity::query()->where('verb', DriveEventType::FolderCreated->value)->exists())->toBeFalse();
});

// ─────────────────────────────── console/queue degrade ────────────────────────

it('degrades to null ip/user-agent/request-id off the HTTP lifecycle', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'console-node');

    // Simulate a console/queue context: no request bound, exactly as RequestContext
    // sees off the HTTP lifecycle. The recorder must not require one.
    app()->forgetInstance('request');
    app()->offsetUnset('request');

    Activity::query()->delete();
    recordActivityEvent(new FolderCreated(nodeSnapshots: app(NodeSnapshotMapper::class), node: $node, actor: recordActivityActor(), correlationId: (string) Str::ulid()));

    $row = Activity::query()->firstOrFail();

    expect($row->ip)->toBeNull()
        ->and($row->user_agent)->toBeNull()
        ->and($row->request_id)->toBeNull();
});

it('stores the correlation token the response echoes, including one that is not a uuid', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'traced-node');
    $request = Request::create('/v1/nodes', 'POST');
    $request->headers->set('X-Request-Id', 'cached-1');
    app()->instance('request', $request);

    Activity::query()->delete();
    recordActivityEvent(new FolderCreated(nodeSnapshots: app(NodeSnapshotMapper::class), node: $node, actor: recordActivityActor(), correlationId: (string) Str::ulid()));

    expect(Activity::query()->firstOrFail()->request_id)->toBe('cached-1')
        ->and(AssignCorrelationId::of($request))->toBe('cached-1');
});

it('keeps the write when the client ip or user agent cannot be stored', function (): void {
    $drive = DriveTestHelpers::drive();
    $bad = Request::create('/v1/nodes', 'POST', server: ['REMOTE_ADDR' => 'not-an-ip']);
    // A NUL is valid UTF-8 and still illegal in PostgreSQL text; a non-address is illegal in inet.
    $bad->headers->set('User-Agent', "curl\0agent");
    app()->instance('request', $bad);
    Activity::query()->delete();

    $folder = $drive->actingAs(DriveTestHelpers::actor($drive, 7))->folders()->create('kept');

    $row = ActivityTestHelpers::only(DriveEventType::FolderCreated->value);
    expect($folder->id)->not->toBeNull()
        ->and(DriveTestHelpers::model($drive->root()->id)->children()->where('name', 'kept')->exists())->toBeTrue()
        ->and($row->target_id)->toEqual($folder->id)
        ->and($row->ip)->toBeNull()
        ->and($row->user_agent)->toBeNull();

    $binary = Request::create('/v1/nodes', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
    $binary->headers->set('User-Agent', "curl\xFFagent");
    app()->instance('request', $binary);
    Activity::query()->delete();
    $drive->actingAs(DriveTestHelpers::actor($drive, 7))->folders()->create('binary-agent');

    expect(ActivityTestHelpers::only(DriveEventType::FolderCreated->value)->user_agent)->toBeNull()
        ->and(ActivityTestHelpers::only(DriveEventType::FolderCreated->value)->ip)->toBe('127.0.0.1');

    $good = Request::create('/v1/nodes', 'POST', server: ['REMOTE_ADDR' => '2001:db8::1']);
    $good->headers->set('User-Agent', "curl/8\u{00A0}");
    app()->instance('request', $good);
    Activity::query()->delete();
    $again = $drive->actingAs(DriveTestHelpers::actor($drive, 7))->folders()->create('traced');
    $kept = ActivityTestHelpers::only(DriveEventType::FolderCreated->value);

    expect($again->id)->not->toBeNull()
        ->and($kept->ip)->toBe('2001:db8::1')
        ->and($kept->user_agent)->toBe("curl/8\u{00A0}");
});

it('does not copy a rejected correlation header into the activity row', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'rejected-trace');
    $request = Request::create('/v1/nodes', 'POST');
    $request->headers->set('X-Request-Id', "cached-1\nbad");
    app()->instance('request', $request);

    Activity::query()->delete();
    recordActivityEvent(new FolderCreated(nodeSnapshots: app(NodeSnapshotMapper::class), node: $node, actor: recordActivityActor(), correlationId: (string) Str::ulid()));

    $stored = Activity::query()->firstOrFail()->request_id;

    expect($stored)->not->toBe("cached-1\nbad")
        ->and($stored)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

// ─────────────────────── an event of the kernel that is no DriveEvent ───────────────────────

/** A DomainEvent that is no DriveEvent: it carries no activity target and no journal entry. */
function plainDomainEvent(string $driveId): DomainEvent
{
    return new readonly class(recordActivityActor(), 'corr-plain', $driveId) extends DomainEvent
    {
        public function __construct(ActorRef $actor, string $correlationId, private string $driveId)
        {
            parent::__construct($actor, $correlationId);
        }

        public function type(): EventTypeContract
        {
            return DriveEventType::DriveCreated;
        }

        public function scope(): EventScope
        {
            return new EventScope(tenantId: null, driveId: $this->driveId, profile: 'default');
        }

        public function subject(): EventSubject
        {
            return new EventSubject(type: 'drive', id: $this->driveId);
        }

        public function data(): array
        {
            return ['name' => 'plain'];
        }
    };
}

it('records an activity row without a target for an event that is no DriveEvent, and journals nothing for it', function (): void {
    $storage = DriveTestHelpers::drive()->model();
    Activity::query()->delete();
    $event = plainDomainEvent((string) $storage->getKey());

    $inserted = app(ActivityRecorder::class)->record($event);
    $sink = new ChangeJournalSink(app(DriveOptions::class), app())->record($event, new SinkWriter(app(VaulterDatabase::class)));

    $row = ActivityTestHelpers::only(DriveEventType::DriveCreated->value);

    expect($inserted)->toBeTrue()
        ->and($row->target_id)->toBeNull()
        ->and($row->target_type)->toBeNull()
        ->and($sink)->toBe(SinkResult::Skipped)
        ->and($event instanceof DriveEvent)->toBeFalse();
});

it('gives a DriveEvent a null activity target and no journal entry until it names them', function (): void {
    $event = new readonly class(recordActivityActor(), 'corr-bare') extends DriveEvent
    {
        public function type(): EventTypeContract
        {
            return DriveEventType::DriveCreated;
        }

        public function scope(): EventScope
        {
            return new EventScope(tenantId: null, driveId: null, profile: 'default');
        }

        public function subject(): EventSubject
        {
            return new EventSubject(type: 'drive', id: 'd');
        }

        public function data(): array
        {
            return [];
        }
    };

    expect($event->activityTarget())->toBeNull()
        ->and($event->journal())->toBeNull();
});
