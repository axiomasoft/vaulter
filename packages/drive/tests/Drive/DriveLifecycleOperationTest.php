<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Exceptions\Idempotency\IdempotencyKeyReusedException;

/**
 * Archive, unarchive and delete of a drive run as the operations drive.drives.* — through the runner, so
 * they take an idempotency key (a write outside the runner would refuse it) and keep one receipt — and compare the
 * expected version under the drive's row lock; each change raises the version by one.
 */
beforeEach(function (): void {
    $this->system = V1::drive(userId: 1);
    $this->owner = $this->system->actingAs(V1::user(1));
});

it('raises the version with each change and refuses a stale expected version', function (): void {
    $archived = $this->owner->archive(0);
    expect($archived->status)->toBe(DriveStatus::Archived)->and($archived->version)->toBe(1);

    expect(fn () => $this->owner->unarchive(0))->toThrow(StaleWriteException::class);
    expect($this->owner->snapshot()->status)->toBe(DriveStatus::Archived);

    expect($this->owner->unarchive(1)->version)->toBe(2)
        ->and($this->owner->archive()->version)->toBe(3);
});

it('replays an archive under its key once and refuses another request under it', function (): void {
    $keyed = $this->owner->withIdempotencyKey('archive-1');

    $first = $keyed->archive(0);
    $again = $keyed->archive(0);

    expect($again)->toEqual($first)
        ->and(OutboxEvent::query()->where('type', DriveEventType::DriveArchived->value)->count())->toBe(1)
        ->and(fn () => $keyed->archive(1))->toThrow(IdempotencyKeyReusedException::class);
});

it('authorizes inside the operation: a reader is refused and the system actor passes', function (): void {
    AccessTestHelpers::grant(DriveTestHelpers::model($this->owner->root()), RoleRegistry::READER, subjectId: 2);

    expect(fn () => $this->system->actingAs(V1::user(2))->archive())->toThrow(PermissionDeniedException::class)
        ->and($this->system->archive()->status)->toBe(DriveStatus::Archived);
});

it('starts the delete once and leaves the drive absent to a retry', function (): void {
    Queue::fake();
    $keyed = $this->owner->withIdempotencyKey('delete-1');

    expect($keyed->delete(0)->status)->toBe(DriveStatus::Deleting)
        ->and(fn () => $keyed->delete(0))->toThrow(StorageNotFoundException::class);
});
