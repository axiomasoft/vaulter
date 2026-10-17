<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vaulter\Data\Activity\RecordActivityCommand;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Models\Activity\Activity;
use Vaulter\Repositories\Activity\ActivityStoreRepository;
use Vaulter\Tests\Fixtures\HostKeyUser;

it('round-trips a ULID grant subject and actor with bigint Vaulter IDs', function (): void {
    config()->set('vaulter.ids.host_keys', 'string');
    $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $user = (new HostKeyUser)->forceFill(['id' => $ulid]);
    $user->exists = true;
    $this->actingAs($user);
    $drive = DriveTestHelpers::drive(42);
    AccessTestHelpers::grant($drive->root(), RoleRegistry::OWNER, subjectId: $user->getKey(), subjectType: $user->getMorphClass());
    $node = $drive->actingAs($user)->folders()->create('ULID actor');
    $grant = AccessTestHelpers::grant($node, RoleRegistry::READER, subjectId: $ulid, subjectType: $user->getMorphClass());
    $eventId = (string) Str::ulid();
    app(ActivityStoreRepository::class)->record(new RecordActivityCommand(
        eventId: $eventId,
        verb: DriveEventType::FolderCreated,
        storageId: $drive->model()->getKey(),
        actorType: $user->getMorphClass(),
        actorId: $ulid,
    ));
    $activity = Activity::query()->where('event_id', $eventId)->sole();

    expect($grant->subject_id)->toBe($ulid)
        ->and($activity->actor_id)->toBe($ulid)
        ->and($node->createdBy)->toBe($ulid)
        ->and($node->id)->toBeString()->toBeNumeric();
});
