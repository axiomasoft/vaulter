<?php

declare(strict_types=1);

use Spatie\Permission\Models\Role;
use Vaulter\Data\Activity\RecordActivityCommand;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Activity\ActivityVerb;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Panels\Panel;
use Vaulter\Repositories\Activity\ActivityStoreRepository;
use Vaulter\Schema\PanelTablesBuilder;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\HostKeyUser;

// Build a fresh panel after upgrade migrations, so the regression cannot be
// hidden by the upgrade repairing an incorrectly emitted fresh column.
beforeEach(function (): void {
    config()->set('vaulter.ids.host_keys', 'string');
    config()->set('vaulter.table_prefix', 'hk_v23_');
    (new PanelTablesBuilder)->createAll((new Panel)->id('default'));
});
afterEach(function (): void {
    (new PanelTablesBuilder)->dropAll((new Panel)->id('default'));
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
    $storage = $drive->storage()->refresh();
    $node = $drive->createFolder('UUID actor', actorId: $uuid)->refresh();
    $grant = AccessTestHelpers::grant($node, GrantLevel::Reader, subjectId: $user->getKey(), subjectType: $user->getMorphClass());
    $roleGrant = AccessTestHelpers::grant($node, GrantLevel::Reader, subjectId: $role->getKey(), subjectType: $role->getMorphClass());
    $activity = app(ActivityStoreRepository::class)->record(new RecordActivityCommand(
        verb: ActivityVerb::NodeCreated,
        storageId: $storage->getKey(),
        actorType: $user->getMorphClass(),
        actorId: $uuid,
    ))->refresh();

    expect($storage->owner_id)->toBe('42')
        ->and($node->created_by)->toBe($uuid)
        ->and($grant->subject_id)->toBe($uuid)
        ->and($roleGrant->subject_id)->toBe('42')
        ->and($activity->actor_id)->toBe($uuid)
        ->and(NodeGrant::query()->where('subject_type', $user->getMorphClass())->where('subject_id', $uuid)->count())->toBe(1)
        ->and(strlen((string) $node->getKey()))->toBe(26);
});
