<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\MoveOptions;
use Vaulter\Drive\Data\RestoreOptions;
use Vaulter\Drive\Data\Snapshots\UploadSessionSnapshot;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Events\Nodes\NodeMoved;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\StaleNodeException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Drive\Uploads\UploadsOf;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Exceptions\Idempotency\IdempotencyKeyReusedException;
use Vaulter\Idempotency\IdempotencyStore;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * P6.2 (D25, D47, ID1–ID4): every receipt operation of nodes, trash, files and uploads answers a repeat under its
 * Idempotency-Key with the stored result and a single effect and event; another request under the key is refused; a
 * replay is authorized again where the node is now (move, copy, trash) or in the recorded scope (purge, revision
 * delete). The natural operations (star, retention, abort) have no key: a repeat leaves one effect.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->system = V1::drive(userId: 1);
    $this->actor = $this->system->actingAs(V1::user(1));
    $this->keyed = fn (string $key): DriveHandle => $this->actor->withIdempotencyKey($key);
});

function nodeEvents(DriveEventType $type): int
{
    return OutboxEvent::query()->where('type', $type->value)->count();
}

/** Takes every grant of user `$id` on the drive's root away. */
function nodeRevokeUser(DriveHandle $system, int|string $id): void
{
    $root = $system->root();
    foreach ($system->access()->grants($root->id) as $grant) {
        if ((string) $grant->subject->id === (string) $id) {
            $system->access()->revoke($root->id, $grant->id);
        }
    }
}

/** Gives user `$id` only `$role` on the drive's root (a downgrade that keeps View). */
function nodeRegrantUser(DriveHandle $system, int|string $id, string $role): void
{
    nodeRevokeUser($system, $id);
    $system->access()->grant($system->root()->id, new GrantInput(subject: Subject::ofModel(V1::user((int) $id)), role: $role));
}

/** Whether `$write` is refused like a new request of an actor without the right. */
function nodeReplayDenied(Closure $write): bool
{
    try {
        $write();
    } catch (NodeNotFoundException|PermissionDeniedException) {
        return true;
    }

    return false;
}

it('replays a move with one event, even when its If-Match went stale', function (): void {
    $file = V1::file($this->system, 'a.txt');
    $folder = V1::folder($this->system, 'Target');
    $options = new MoveOptions(expectedSequence: $file->sequence);

    $first = ($this->keyed)('move-1')->nodes()->move($file->id, $folder->id, $options);
    $second = ($this->keyed)('move-1')->nodes()->move($file->id, $folder->id, $options);

    expect($second->parentId)->toBe($folder->id)->and($second->sequence)->toBe($first->sequence)->and($second->id)->toBe($first->id)
        ->and(nodeEvents(DriveEventType::NodeMoved))->toBe(1);
    // Without a key the same stale precondition is refused.
    expect(fn () => $this->actor->nodes()->move($file->id, $folder->id, $options))->toThrow(StaleNodeException::class);
});

it('refuses another request under the key of a move', function (): void {
    $file = V1::file($this->system, 'a.txt');
    $one = V1::folder($this->system, 'One');
    $two = V1::folder($this->system, 'Two');
    ($this->keyed)('move-2')->nodes()->move($file->id, $one->id);

    expect(fn () => ($this->keyed)('move-2')->nodes()->move($file->id, $two->id))->toThrow(IdempotencyKeyReusedException::class);
    expect(fn () => ($this->keyed)('move-2')->nodes()->move($file->id, $one->id, new MoveOptions(expectedDestinationSequence: 3)))->toThrow(IdempotencyKeyReusedException::class);
});

it('refuses a move whose destination folder changed since the caller read it', function (): void {
    $file = V1::file($this->system, 'a.txt');
    $folder = V1::folder($this->system, 'Target');
    $seen = $folder->sequence;
    $this->system->nodes()->rename($folder->id, 'Renamed');

    expect(fn () => $this->actor->nodes()->move($file->id, $folder->id, new MoveOptions(expectedDestinationSequence: $seen)))->toThrow(StaleNodeException::class);
    expect((string) DriveTestHelpers::model($file)->parent_id)->not->toBe($folder->id);

    $moved = $this->actor->nodes()->move($file->id, $folder->id, new MoveOptions(expectedDestinationSequence: $this->system->nodes()->find($folder->id)->sequence));
    expect($moved->parentId)->toBe($folder->id);
});

it('denies the replay of a move once the node sits in a folder the actor cannot see', function (): void {
    $file = V1::file($this->system, 'a.txt');
    $folder = V1::folder($this->system, 'Target');
    ($this->keyed)('move-3')->nodes()->move($file->id, $folder->id);
    // Close the folder to everyone but a grant the actor does not hold.
    $this->system->access()->restrictInheritance($folder->id);

    expect(nodeReplayDenied(fn () => ($this->keyed)('move-3')->nodes()->move($file->id, $folder->id)))->toBeTrue()
        ->and(nodeEvents(DriveEventType::NodeMoved))->toBe(1);
});

it('denies the replay of any receipt operation to an actor whose access was revoked', function (): void {
    $file = V1::file($this->system, 'a.txt');
    ($this->keyed)('rev-1')->nodes()->setProperties($file->id, 'acme', ['k' => 'v']);
    nodeRevokeUser($this->system, 1);

    expect(nodeReplayDenied(fn () => ($this->keyed)('rev-1')->nodes()->setProperties($file->id, 'acme', ['k' => 'v'])))->toBeTrue();
});

it('replays a copy and makes one copy', function (): void {
    $file = V1::file($this->system, 'a.txt');
    $folder = V1::folder($this->system, 'Into');

    $first = ($this->keyed)('copy-1')->nodes()->copy($file->id, $folder->id, new CopyOptions(name: 'b.txt'));
    $second = ($this->keyed)('copy-1')->nodes()->copy($file->id, $folder->id, new CopyOptions(name: 'b.txt'));

    expect($second->id)->toBe($first->id)->and(Node::query()->where('name', 'b.txt')->count())->toBe(1)
        ->and(nodeEvents(DriveEventType::NodeCopied))->toBe(1);
    expect(fn () => ($this->keyed)('copy-1')->nodes()->copy($file->id, $folder->id, new CopyOptions(name: 'c.txt')))->toThrow(IdempotencyKeyReusedException::class);
});

it('replays set_properties after its If-Match went stale', function (): void {
    $file = V1::file($this->system, 'a.txt');

    $first = ($this->keyed)('props-1')->nodes()->setProperties($file->id, 'acme', ['k' => 'v'], expectedSequence: $file->sequence);
    $second = ($this->keyed)('props-1')->nodes()->setProperties($file->id, 'acme', ['k' => 'v'], expectedSequence: $file->sequence);

    expect($second->sequence)->toBe($first->sequence)->and($second->properties)->toBe(['acme' => ['k' => 'v']])
        ->and(nodeEvents(DriveEventType::NodeUpdated))->toBe(1);
    expect(fn () => ($this->keyed)('props-1')->nodes()->setProperties($file->id, 'acme', ['k' => 'other'], expectedSequence: $file->sequence))->toThrow(IdempotencyKeyReusedException::class);
});

it('replays a trash that already put the node in the trash, and its restore', function (): void {
    $folder = V1::folder($this->system, 'Bin me');

    $first = ($this->keyed)('trash-1')->nodes()->trash($folder->id, $folder->sequence);
    $second = ($this->keyed)('trash-1')->nodes()->trash($folder->id, $folder->sequence);
    expect($second->sequence)->toBe($first->sequence)->and($second->trashedAt)->not->toBeNull()->and(nodeEvents(DriveEventType::NodeTrashed))->toBe(1);

    $restored = ($this->keyed)('restore-1')->trash()->restore($folder->id, new RestoreOptions(expectedSequence: $first->sequence));
    $again = ($this->keyed)('restore-1')->trash()->restore($folder->id, new RestoreOptions(expectedSequence: $first->sequence));
    expect($again->sequence)->toBe($restored->sequence)->and($again->trashedAt)->toBeNull()->and(nodeEvents(DriveEventType::NodeRestored))->toBe(1);
});

it('replays a purge although the node is gone, by the recorded scope', function (): void {
    $parent = V1::folder($this->system, 'Parent');
    $child = V1::folder($this->system, 'Child', $parent->id);
    $trashed = $this->actor->nodes()->trash($child->id);

    ($this->keyed)('purge-1')->trash()->purge($child->id, $trashed->sequence);
    expect(Node::query()->whereKey($child->id)->exists())->toBeFalse();

    // The parent goes too: the right to delete is checked in the recorded scope, then in the drive's root.
    $this->actor->nodes()->trash($parent->id);
    $this->actor->trash()->purge($parent->id);
    ($this->keyed)('purge-1')->trash()->purge($child->id, $trashed->sequence);

    expect(nodeEvents(DriveEventType::NodePurged))->toBe(2);
});

it('denies the replay of a purge to an actor who may not delete there any more', function (): void {
    $folder = V1::folder($this->system, 'Gone');
    $trashed = $this->actor->nodes()->trash($folder->id);
    ($this->keyed)('purge-2')->trash()->purge($folder->id, $trashed->sequence);
    nodeRevokeUser($this->system, 1);

    expect(nodeReplayDenied(fn () => ($this->keyed)('purge-2')->trash()->purge($folder->id, $trashed->sequence)))->toBeTrue();
});

it('does not treat a deleted node grant as current Delete on its surviving parent', function (): void {
    $parent = V1::folder($this->system, 'Parent');
    $child = V1::folder($this->system, 'Child', $parent->id);
    $subject = Subject::ofModel(V1::user(2));
    $this->system->access()->grant($this->system->root()->id, new GrantInput(subject: $subject, role: RoleRegistry::PREVIEWER));
    $this->system->access()->grant($child->id, new GrantInput(subject: $subject, role: RoleRegistry::OWNER));
    $actor = $this->system->actingAs(V1::user(2))->withIdempotencyKey('purge-child-only');
    $trashed = $this->system->nodes()->trash($child->id);

    $actor->trash()->purge($child->id, $trashed->sequence);
    expect(Node::query()->whereKey($child->id)->exists())->toBeFalse();
    expect(fn () => $actor->trash()->purge($child->id, $trashed->sequence))->toThrow(PermissionDeniedException::class)
        ->and(nodeEvents(DriveEventType::NodePurged))->toBe(1);
});

it('replays an emptied trash with its count and does not empty a later one', function (): void {
    foreach (['a', 'b'] as $name) {
        $this->actor->nodes()->trash(V1::folder($this->system, $name)->id);
    }

    $first = ($this->keyed)('empty-1')->trash()->empty();
    $later = V1::folder($this->system, 'later');
    $this->actor->nodes()->trash($later->id);
    $second = ($this->keyed)('empty-1')->trash()->empty();

    expect($first)->toBe(2)->and($second)->toBe(2)->and(Node::query()->whereKey($later->id)->exists())->toBeTrue()
        ->and(nodeEvents(DriveEventType::NodePurged))->toBe(2);
    expect($this->actor->trash()->empty())->toBe(1);
});

it('refuses the count of an emptied trash once Delete on the root is gone, and keeps it for the owner', function (string $role): void {
    $this->actor->nodes()->trash(V1::folder($this->system, 'a')->id);
    expect(($this->keyed)('empty-owner')->trash()->empty())->toBe(1)
        ->and(($this->keyed)('empty-owner')->trash()->empty())->toBe(1);
    $this->actor->nodes()->trash(V1::folder($this->system, 'b')->id);
    expect(($this->keyed)('empty-lost')->trash()->empty())->toBe(1);
    $this->actor->nodes()->trash(V1::folder($this->system, 'later')->id);
    nodeRegrantUser($this->system, 1, $role);

    expect(fn () => ($this->keyed)('empty-lost')->trash()->empty())->toThrow(PermissionDeniedException::class)
        ->and($this->actor->trash()->empty())->toBe(0)
        ->and($this->actor->nodes()->find($this->system->root()->id)->id)->toBe($this->system->root()->id)
        ->and(nodeEvents(DriveEventType::NodePurged))->toBe(2);
    nodeRevokeUser($this->system, 1);
    expect(fn () => ($this->keyed)('empty-lost')->trash()->empty())->toThrow(NodeNotFoundException::class);
})->with([RoleRegistry::READER, RoleRegistry::WRITER, RoleRegistry::ORGANIZER]);

it('refuses the count of an emptied trash once the drive profile shuts its owner out', function (): void {
    $this->actor->nodes()->trash(V1::folder($this->system, 'a')->id);
    expect(($this->keyed)('empty-profile')->trash()->empty())->toBe(1);
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key($this->system->model()->profile)->owners(TestUser::class));
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    app()->forgetInstance(ProfilePolicy::class);

    expect(fn () => ($this->keyed)('empty-profile')->trash()->empty())->toThrow(ProfileRestrictionException::class)
        ->and(nodeEvents(DriveEventType::NodePurged))->toBe(1);
});

it('replays a restored revision and a deleted revision', function (): void {
    $file = V1::file($this->system, 'a.txt', 'one');
    $this->system->files()->addRevision($file->id, UploadSource::fromStream(DriveTestHelpers::streamFor('two'), 'a.txt'));
    $this->system->files()->addRevision($file->id, UploadSource::fromStream(DriveTestHelpers::streamFor('three'), 'a.txt'));
    $revisions = DriveTestHelpers::model($file)->file()->firstOrFail()->versions()->orderBy('number')->get();
    $head = $this->system->nodes()->find($file->id);

    $first = ($this->keyed)('rr-1')->files()->restoreRevision($file->id, (string) $revisions[0]->getKey(), $head->sequence);
    $second = ($this->keyed)('rr-1')->files()->restoreRevision($file->id, (string) $revisions[0]->getKey(), $head->sequence);
    expect($second->id)->toBe($first->id)->and($second->number)->toBe(4)->and($revisions[0]->newQuery()->count())->toBe(4);

    ($this->keyed)('dr-1')->files()->deleteRevision($file->id, (string) $revisions[1]->getKey());
    ($this->keyed)('dr-1')->files()->deleteRevision($file->id, (string) $revisions[1]->getKey());
    expect(nodeEvents(DriveEventType::FileRevisionDeleted))->toBe(1);
    expect(fn () => ($this->keyed)('dr-1')->files()->deleteRevision($file->id, (string) $revisions[0]->getKey()))->toThrow(IdempotencyKeyReusedException::class);
});

it('replays an upload initiation with the same session', function (): void {
    $folder = V1::folder($this->system, 'Uploads');
    $input = new InitiateUploadInput(protocol: UploadProtocol::Tus, targetParent: $folder->id, targetName: 'big.bin', totalSizeBytes: 10);

    $first = UploadsOf::handle(($this->keyed)('up-1'))->initiate($input);
    $session = UploadSession::query()->findOrFail($first->id);
    $persisted = $session->getRawOriginal();
    $second = UploadsOf::handle(($this->keyed)('up-1'))->initiate($input);

    expect($second)->toBeInstanceOf(UploadSessionSnapshot::class)->toEqual($first)
        ->and($second->uploadId)->toBe($first->uploadId)->and($second->totalSizeBytes)->toBe(10)
        ->and($second->targetParentId)->toBe($folder->id)
        ->and($session->fresh()->getRawOriginal())->toBe($persisted)
        ->and(UploadSession::query()->count())->toBe(1)->and(nodeEvents(DriveEventType::UploadInitiated))->toBe(1);
    expect(fn () => UploadsOf::handle(($this->keyed)('up-1'))->initiate(new InitiateUploadInput(protocol: UploadProtocol::Tus, targetParent: $folder->id, targetName: 'big.bin', totalSizeBytes: 11)))->toThrow(IdempotencyKeyReusedException::class);
});

it('leaves one effect and one event for a repeated star, unstar and retention', function (): void {
    $file = V1::file($this->system, 'a.txt');

    $this->actor->nodes()->star($file->id);
    $this->actor->nodes()->star($file->id);
    expect($this->actor->nodes()->find($file->id)->viewer?->starred)->toBeTrue();
    $this->actor->nodes()->unstar($file->id);
    $this->actor->nodes()->unstar($file->id);
    expect($this->actor->nodes()->find($file->id)->viewer?->starred)->toBeFalse();

    $revision = (string) DriveTestHelpers::model($file)->file()->firstOrFail()->current_revision_id;
    $one = $this->actor->files()->setRevisionRetention($file->id, $revision, true);
    $two = $this->actor->files()->setRevisionRetention($file->id, $revision, true);
    expect($one->keepForever)->toBeTrue()->and($two->keepForever)->toBeTrue();
});

it('aborts an upload once; a repeat leaves it aborted without a second event', function (): void {
    $folder = V1::folder($this->system, 'Abort');
    $session = UploadsOf::handle($this->actor)->initiate(new InitiateUploadInput(protocol: UploadProtocol::Tus, targetParent: $folder->id, targetName: 'x.bin', totalSizeBytes: 4));

    UploadsOf::handle($this->actor)->abort($session);
    try {
        UploadsOf::handle($this->actor)->abort($session);
    } catch (Throwable) {
        // A closed session may refuse the repeat; either way there is no second abort.
    }

    expect(nodeEvents(DriveEventType::UploadAborted))->toBe(1);
});

it('commits a keyed move once when its after-commit listener fails, and replays it without a second event or listener call', function (): void {
    $file = V1::file($this->system, 'a.txt');
    $folder = V1::folder($this->system, 'Target');
    $calls = 0;
    Event::listen(static function (NodeMoved $event) use (&$calls): void {
        $calls++;

        throw new RuntimeException('listener failed after the commit');
    });

    $first = ($this->keyed)('after-commit-1')->nodes()->move($file->id, $folder->id);
    $again = ($this->keyed)('after-commit-1')->nodes()->move($file->id, $folder->id);

    expect($again)->toEqual($first)
        ->and($calls)->toBe(1)
        ->and(nodeEvents(DriveEventType::NodeMoved))->toBe(1)
        ->and(Node::query()->findOrFail($file->id)->sequence)->toBe($first->sequence);
});

it('keeps the raw receipt of a move semantic: the codec fields and version, no tag, abilities or sync key', function (): void {
    $file = V1::file($this->system, 'a.txt');
    $folder = V1::folder($this->system, 'Target');
    $moved = ($this->keyed)('raw-move-1')->nodes()->move($file->id, $folder->id);

    /** @var stdClass $row */
    $row = app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table(IdempotencyStore::ENTITY))->where('idempotency_key', base64_encode('raw-move-1'))->first();
    $body = json_decode((string) $row->receipt_body, true, flags: JSON_THROW_ON_ERROR);

    expect($row->receipt_kind)->toBe('drive.node-snapshot')
        ->and((int) $row->codec_version)->toBe(1)
        ->and($row->receipt_headers)->toBeNull()
        ->and($body['parent_id'])->toBe($folder->id)
        ->and($body['sequence'])->toBe($moved->sequence)
        ->and(array_keys($body))->not->toContain('etag', 'abilities', 'sync_key', 'links')
        ->and((string) $row->receipt_body)->not->toContain('sync_key');
});
