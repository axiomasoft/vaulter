<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\Activity\RecordActivityCommand;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Repositories\Activity\ActivityStoreRepository;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Schema\ModuleSchemas;
use Vaulter\Tests\Fixtures\HostKeyUser;

// Build a fresh panel after upgrade migrations, so the regression cannot be
// hidden by the upgrade repairing an incorrectly emitted fresh column.
beforeEach(function (): void {
    config()->set('vaulter.ids.host_keys', 'string');
    config()->set('vaulter.database.table_prefix', 'hk_v23_');
    app(ModuleSchemas::class)->createAll();
});
afterEach(function (): void {
    app(ModuleSchemas::class)->dropAll();
});

it('round-trips UUID users and bigint projects and Spatie roles with ULID Vaulter IDs', function (): void {
    config()->set('vaulter.ids.host_keys', 'string');
    $uuid = '550e8400-e29b-41d4-a716-446655440000';
    $user = (new HostKeyUser)->forceFill(['id' => $uuid]);
    $user->exists = true;
    $this->actingAs($user);
    config()->set('permission.table_names.roles', 'roles');
    $role = (new Role)->forceFill(['id' => 42, 'name' => 'editor', 'guard_name' => 'web']);
    $role->exists = true;

    $drive = DriveTestHelpers::drive(42);
    $storage = $drive->model()->refresh();
    AccessTestHelpers::grant($drive->root(), RoleRegistry::OWNER, subjectId: $user->getKey(), subjectType: $user->getMorphClass());
    $node = $drive->actingAs($user)->folders()->create('UUID actor');
    $grant = AccessTestHelpers::grant($node, RoleRegistry::READER, subjectId: $user->getKey(), subjectType: $user->getMorphClass());
    $roleGrant = AccessTestHelpers::grant($node, RoleRegistry::READER, subjectId: $role->getKey(), subjectType: $role->getMorphClass());
    $eventId = (string) Str::ulid();
    app(ActivityStoreRepository::class)->record(new RecordActivityCommand(
        eventId: $eventId,
        verb: DriveEventType::FolderCreated,
        storageId: $storage->getKey(),
        actorType: $user->getMorphClass(),
        actorId: $uuid,
    ));
    $activity = Activity::query()->where('event_id', $eventId)->sole();

    expect($storage->owner_id)->toBe('42')
        ->and($node->createdBy)->toBe($uuid)
        ->and($grant->subject_id)->toBe($uuid)
        ->and($roleGrant->subject_id)->toBe('42')
        ->and($activity->actor_id)->toBe($uuid)
        ->and(NodeGrant::query()->where('node_id', $node->id)->where('subject_type', $user->getMorphClass())->where('subject_id', $uuid)->count())->toBe(1)
        ->and(strlen((string) $node->id))->toBe(26);
});
