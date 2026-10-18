<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\MoveOptions;
use Vaulter\Drive\Data\RestoreOptions;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\StaleNodeException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Drive\Uploads\UploadsOf;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Exceptions\Idempotency\IdempotencyKeyReusedException;

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
    $second = UploadsOf::handle(($this->keyed)('up-1'))->initiate($input);

    expect($second)->toBeInstanceOf(UploadSession::class)->and($second->upload_id)->toBe($first->upload_id)->and($second->total_size_bytes)->toBe(10)
        ->and($second->target_parent_id)->toBe($first->target_parent_id)
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
