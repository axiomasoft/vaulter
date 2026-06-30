<?php

declare(strict_types=1);

use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\Permission;

/**
 * The bitmask/level/ability mapping (master plan §2.6 / H.2): the single int the
 * whole engine reasons over. These pin the exact bits so a future edit cannot
 * silently widen or narrow a level.
 */
it('computes ALL as the OR of every CRUDS bit', function (): void {
    expect(Permission::allBits())->toBe(31)
        ->and(Permission::All->value)->toBe(31);
});

it('ranks and bit-maps each grant level', function (
    GrantLevel $level,
    int $rank,
    int $bits,
): void {
    expect($level->rank())->toBe($rank)
        ->and($level->bits())->toBe($bits);
})->with([
    'reader' => [GrantLevel::Reader, 1, 1],            // READ
    'commenter' => [GrantLevel::Commenter, 2, 1],         // READ
    'writer' => [GrantLevel::Writer, 3, 7],            // READ|UPDATE|CREATE
    'organizer' => [GrantLevel::Organizer, 4, 15],        // +DELETE
    'owner' => [GrantLevel::Owner, 5, 31],            // ALL
]);

it('implies lower-ranked levels but not higher ones', function (): void {
    expect(GrantLevel::Owner->implies(GrantLevel::Reader))->toBeTrue()
        ->and(GrantLevel::Reader->implies(GrantLevel::Owner))->toBeFalse()
        ->and(GrantLevel::Writer->max(GrantLevel::Reader))->toBe(GrantLevel::Writer);
});

it('maps each ability to its required bits', function (
    Ability $ability,
    int $bits,
): void {
    expect($ability->requiredBits())->toBe($bits);
})->with([
    'view' => [Ability::View, 1],
    'download' => [Ability::Download, 1],
    'comment' => [Ability::Comment, 1],
    'edit' => [Ability::Edit, 2],
    'create' => [Ability::Create, 4],
    'move' => [Ability::Move, 6],     // UPDATE|CREATE
    'copy' => [Ability::Copy, 4],
    'trash' => [Ability::Trash, 8],
    'restore' => [Ability::Restore, 8],
    'delete' => [Ability::Delete, 8],   // collapses into DELETE (no orphan action)
    'share' => [Ability::Share, 16],
    'manage' => [Ability::Manage, 31],
]);

it('satisfies an ability only when ALL its required bits are present', function (): void {
    // Move needs UPDATE|CREATE (6); READ|UPDATE (3) is not enough, READ|UPDATE|CREATE (7) is.
    expect(Ability::Move->satisfiedBy(0b011))->toBeFalse()
        ->and(Ability::Move->satisfiedBy(0b111))->toBeTrue()
        ->and(Ability::Manage->satisfiedBy(31))->toBeTrue()
        ->and(Ability::Manage->satisfiedBy(15))->toBeFalse();
});
