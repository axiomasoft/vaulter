<?php

declare(strict_types=1);

use Vaulter\Drive\Access\Roles\Role;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\Permission;
use Vaulter\Drive\Exceptions\Access\UnknownRoleException;

/**
 * The bit layout, the ability → bit map and the v1 role table. These
 * pin the exact sets so a future edit cannot silently widen or narrow a role: a
 * changed set must be a new role version.
 */
it('gives each ability its own bit and never reuses one', function (): void {
    expect(Permission::all())->toBe(4095)
        ->and(array_column(Permission::cases(), 'value'))->toBe([1, 2, 4, 8, 16, 32, 64, 128, 256, 512, 1024, 2048])
        ->and(array_map(static fn (Ability $ability): int => $ability->bits(), Ability::cases()))
        ->toBe([1, 64, 32, 2, 4, 128, 256, 512, 1024, 16, 2048, 8]);
});

it('maps each ability to exactly its own bit', function (Ability $ability, int $bits): void {
    expect($ability->bits())->toBe($bits)
        ->and(Ability::fromMask($bits))->toBe([$ability]);
})->with([
    'view' => [Ability::View, 1],
    'download' => [Ability::Download, 64],
    'comment' => [Ability::Comment, 32],
    'edit' => [Ability::Edit, 2],
    'create' => [Ability::Create, 4],
    'move' => [Ability::Move, 128],
    'copy' => [Ability::Copy, 256],
    'trash' => [Ability::Trash, 512],
    'restore' => [Ability::Restore, 1024],
    'delete' => [Ability::Delete, 8],
    'share' => [Ability::Share, 16],
    'manage' => [Ability::Manage, 2048],
]);

it('satisfies an ability by its bit alone; view and download are independent', function (): void {
    expect(Ability::Download->satisfiedBy(Ability::View->bits()))->toBeFalse()
        ->and(Ability::View->satisfiedBy(Ability::Download->bits()))->toBeFalse()
        ->and(Ability::Manage->satisfiedBy(Permission::all() & ~Permission::Manage->value))->toBeFalse()
        ->and(Ability::Move->satisfiedBy(Ability::mask([Ability::Edit, Ability::Create])))->toBeFalse();
});

it('pins the v1 role table', function (string $role, array $abilities): void {
    $resolved = (new RoleRegistry)->get($role, 1);

    expect($resolved->key)->toBe($role)
        ->and($resolved->version)->toBe(1)
        ->and(array_map(static fn (Ability $ability): string => $ability->value, Ability::fromMask($resolved->bits)))->toBe($abilities);
})->with([
    'previewer' => [RoleRegistry::PREVIEWER, ['view']],
    'reader' => [RoleRegistry::READER, ['view', 'download']],
    'commenter' => [RoleRegistry::COMMENTER, ['view', 'download', 'comment']],
    'writer' => [RoleRegistry::WRITER, ['view', 'download', 'comment', 'edit', 'create', 'move', 'copy', 'trash', 'restore']],
    'organizer' => [RoleRegistry::ORGANIZER, ['view', 'download', 'comment', 'edit', 'create', 'move', 'copy', 'trash', 'restore', 'share']],
    'owner' => [RoleRegistry::OWNER, ['view', 'download', 'comment', 'edit', 'create', 'move', 'copy', 'trash', 'restore', 'share', 'manage', 'delete']],
]);

it('nests the built-in roles so any set of them unions to one of them', function (): void {
    $roles = (new RoleRegistry)->all();
    expect(array_map(static fn (Role $role): string => $role->key, $roles))
        ->toBe(['previewer', 'reader', 'commenter', 'writer', 'organizer', 'owner']);
    foreach ($roles as $i => $role) {
        foreach (array_slice($roles, 0, $i) as $narrower) {
            expect($role->covers($narrower))->toBeTrue()->and($narrower->covers($role))->toBeFalse();
        }
    }
    expect(Role::covering([$roles[1], $roles[4], $roles[2]]))->toBe($roles[4])
        ->and(Role::covering([new Role('a', 1, [Ability::View]), new Role('b', 1, [Ability::Download])]))->toBeNull();
});

it('refuses unknown role keys and versions', function (): void {
    expect(fn () => (new RoleRegistry)->latest('editor'))->toThrow(UnknownRoleException::class)
        ->and(fn () => (new RoleRegistry)->get(RoleRegistry::READER, 2))->toThrow(UnknownRoleException::class)
        ->and((new RoleRegistry)->has(RoleRegistry::READER, 1))->toBeTrue()
        ->and((new RoleRegistry)->has('Reader'))->toBeFalse();
    try {
        (new RoleRegistry)->latest('editor');
    } catch (UnknownRoleException $exception) {
        expect($exception->problemCode())->toBe('unknown_role')->and($exception->httpStatus())->toBe(422);
    }
});
