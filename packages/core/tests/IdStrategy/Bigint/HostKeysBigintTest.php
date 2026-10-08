<?php

declare(strict_types=1);

use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Data\Activity\RecordActivityCommand;
use Vaulter\Enums\Events\EventType;
use Vaulter\Repositories\Activity\ActivityStoreRepository;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
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
    $activity = app(ActivityStoreRepository::class)->record(new RecordActivityCommand(
        verb: EventType::FolderCreated,
        storageId: $drive->model()->getKey(),
        actorType: $user->getMorphClass(),
        actorId: $ulid,
    ))->refresh();

    expect($grant->subject_id)->toBe($ulid)
        ->and($activity->actor_id)->toBe($ulid)
        ->and($node->createdBy)->toBe($ulid)
        ->and($node->id)->toBeString()->toBeNumeric();
});
