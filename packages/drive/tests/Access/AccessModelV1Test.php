<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Access\InheritanceRestriction;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Access\UnknownRoleException;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Operations\AccessOperations;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\ValueObjects\Access\Actor;

/**
 * The 1.0 access model end to end: positive roles unioned
 * up to an inheritance boundary, restrictInheritance/restoreInheritance as Manage
 * operations, copy_current never broader or longer-lived than what was inherited,
 * and a previewer that sees but cannot download.
 */
beforeEach(function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
    $this->drive = AccessTestHelpers::storage();
    $this->owner = AccessTestHelpers::user(1);
    AccessTestHelpers::grant($this->drive->rootNode()->firstOrFail(), RoleRegistry::OWNER, subjectId: 1);
});

function accessV1(TestUser $actor, mixed $drive): AccessOperations
{
    return app()->makeWith(AccessOperations::class, ['context' => DriveContext::for($drive, Actor::user($actor), 'access-v1')]);
}

function accessV1Can(int $user, Ability $ability, mixed $node): bool
{
    return app(AccessResolver::class)->can(AccessTestHelpers::user($user), $ability, $node->refresh());
}

it('walls a subfolder off from a team writer and gives HR a direct reader role (guidance example)', function (): void {
    $proj = AccessTestHelpers::folder($this->drive, null, 'proj');
    $hr = AccessTestHelpers::folder($this->drive, $proj, 'hr');
    $payroll = AccessTestHelpers::folder($this->drive, $hr, 'payroll');
    // Engineer 2 is a writer on /proj; HR user 3 gets reader on /proj/hr only.
    AccessTestHelpers::grant($proj, RoleRegistry::WRITER, subjectId: 2);
    $access = accessV1($this->owner, $this->drive);

    // The owner reaches /proj/hr only through the inherited root grant, so grant first, then wall off.
    $access->grant($hr->id, new GrantInput(subject: Subject::ofModel(AccessTestHelpers::user(3)), role: RoleRegistry::READER));
    $access->restrictInheritance($hr->id);

    expect(accessV1Can(2, Ability::Edit, $proj))->toBeTrue()
        ->and(accessV1Can(2, Ability::View, $hr))->toBeFalse()
        ->and(accessV1Can(2, Ability::View, $payroll))->toBeFalse()
        ->and(accessV1Can(3, Ability::View, $payroll))->toBeTrue()
        ->and(accessV1Can(3, Ability::Download, $payroll))->toBeTrue()
        ->and(accessV1Can(3, Ability::Edit, $payroll))->toBeFalse()
        // The owner's root grant is inherited too, so the owner is walled off as well
        // unless the boundary keeps a direct grant for them.
        ->and(accessV1Can(1, Ability::View, $hr))->toBeFalse();
});

it('lets a previewer see a node but never download it', function (): void {
    $file = AccessTestHelpers::folder($this->drive, null, 'preview-only');
    AccessTestHelpers::grant($file, RoleRegistry::PREVIEWER, scope: GrantScope::Item, subjectId: 4);

    expect(accessV1Can(4, Ability::View, $file))->toBeTrue()
        ->and(accessV1Can(4, Ability::Download, $file))->toBeFalse()
        ->and(accessV1Can(4, Ability::Copy, $file))->toBeFalse();
});

it('requires Manage to restrict or restore inheritance', function (): void {
    $folder = AccessTestHelpers::folder($this->drive, null, 'managed');
    AccessTestHelpers::grant($folder, RoleRegistry::ORGANIZER, subjectId: 5);
    $organizer = accessV1(AccessTestHelpers::user(5), $this->drive);

    expect(fn () => $organizer->restrictInheritance($folder->id))->toThrow(PermissionDeniedException::class)
        ->and($folder->refresh()->inherit_grants)->toBeTrue();

    $node = accessV1($this->owner, $this->drive)->restrictInheritance($folder->id);
    expect($node->inheritsAccess)->toBeFalse()
        ->and(fn () => $organizer->restoreInheritance($folder->id))->toThrow(PermissionDeniedException::class);
});

it('restores inheritance and keeps the direct grants', function (): void {
    $parent = AccessTestHelpers::folder($this->drive, null, 'parent');
    $child = AccessTestHelpers::folder($this->drive, $parent, 'child', inheritGrants: false);
    AccessTestHelpers::grant($parent, RoleRegistry::WRITER, subjectId: 6);
    AccessTestHelpers::grant($child, RoleRegistry::COMMENTER, scope: GrantScope::Item, subjectId: 7);
    AccessTestHelpers::grant($child, RoleRegistry::OWNER, scope: GrantScope::Item, subjectId: 1);

    expect(accessV1Can(6, Ability::View, $child))->toBeFalse();

    $node = accessV1($this->owner, $this->drive)->restoreInheritance($child->id);

    expect($node->inheritsAccess)->toBeTrue()
        ->and(accessV1Can(6, Ability::Edit, $child))->toBeTrue()
        ->and(accessV1Can(7, Ability::Comment, $child))->toBeTrue()
        ->and(NodeGrant::query()->where('node_id', $child->id)->count())->toBe(2);
});

it('copies the inherited roles onto the node with copy_current and keeps access exactly', function (): void {
    $parent = AccessTestHelpers::folder($this->drive, null, 'parent');
    $child = AccessTestHelpers::folder($this->drive, $parent, 'child');
    $expires = Carbon::now()->addDays(3)->startOfSecond();
    AccessTestHelpers::grant($parent, RoleRegistry::WRITER, subjectId: 8);
    AccessTestHelpers::grant($parent, RoleRegistry::READER, subjectId: 9, expiresAt: $expires);
    // An item grant on the parent never reached the child and is not copied.
    AccessTestHelpers::grant($parent, RoleRegistry::OWNER, scope: GrantScope::Item, subjectId: 10);

    accessV1($this->owner, $this->drive)->restrictInheritance($child->id, InheritanceRestriction::CopyCurrent);

    $copies = NodeGrant::query()->where('node_id', $child->id)->get()->keyBy(fn (NodeGrant $grant): string => (string) $grant->subject_id);
    expect($child->refresh()->inherit_grants)->toBeFalse()
        ->and($copies->keys()->sort()->values()->all())->toBe([1, 8, 9])
        ->and($copies['8']->role_key)->toBe(RoleRegistry::WRITER)
        ->and($copies['8']->scope)->toBe(GrantScope::Subtree)
        ->and($copies['8']->expires_at)->toBeNull()
        ->and($copies['9']->role_key)->toBe(RoleRegistry::READER)
        ->and($copies['9']->expires_at?->equalTo($expires))->toBeTrue()
        ->and($copies['1']->role_key)->toBe(RoleRegistry::OWNER)
        ->and(accessV1Can(8, Ability::Edit, $child))->toBeTrue()
        ->and(accessV1Can(9, Ability::Download, $child))->toBeTrue()
        ->and(accessV1Can(10, Ability::View, $child))->toBeFalse();
});

it('never makes a copy broader or longer-lived than the inherited roles', function (): void {
    $top = AccessTestHelpers::folder($this->drive, null, 'top');
    $mid = AccessTestHelpers::folder($this->drive, $top, 'mid');
    $leaf = AccessTestHelpers::folder($this->drive, $mid, 'leaf');
    $soon = Carbon::now()->addDay()->startOfSecond();
    $later = Carbon::now()->addDays(5)->startOfSecond();
    // Subject 11: permanent reader + writer until $soon → one writer copy until $soon.
    AccessTestHelpers::grant($top, RoleRegistry::READER, subjectId: 11);
    AccessTestHelpers::grant($mid, RoleRegistry::WRITER, subjectId: 11, expiresAt: $soon);
    // Subject 12: a direct, permanent previewer on the leaf and an inherited
    // commenter until $later → the narrower direct grant outlives it and stays.
    AccessTestHelpers::grant($mid, RoleRegistry::COMMENTER, subjectId: 12, expiresAt: $later);
    AccessTestHelpers::grant($leaf, RoleRegistry::PREVIEWER, subjectId: 12);
    // Subject 13: a direct reader until $soon and an inherited organizer until
    // $later → the covering organizer until $later replaces it (exact union).
    AccessTestHelpers::grant($top, RoleRegistry::ORGANIZER, subjectId: 13, expiresAt: $later);
    AccessTestHelpers::grant($leaf, RoleRegistry::READER, subjectId: 13, expiresAt: $soon);

    accessV1($this->owner, $this->drive)->restrictInheritance($leaf->id, InheritanceRestriction::CopyCurrent);

    $grant = static fn (int $subject): NodeGrant => NodeGrant::query()->where('node_id', $leaf->id)->where('subject_id', $subject)->sole();
    expect($grant(11)->role_key)->toBe(RoleRegistry::WRITER)
        ->and($grant(11)->expires_at?->equalTo($soon))->toBeTrue()
        ->and($grant(12)->role_key)->toBe(RoleRegistry::PREVIEWER)
        ->and($grant(12)->expires_at)->toBeNull()
        ->and($grant(13)->role_key)->toBe(RoleRegistry::ORGANIZER)
        ->and($grant(13)->expires_at?->equalTo($later))->toBeTrue();
});

it('is a no-op when the node already has the requested inheritance', function (): void {
    $folder = AccessTestHelpers::folder($this->drive, null, 'walled', inheritGrants: false);
    AccessTestHelpers::grant($folder, RoleRegistry::OWNER, scope: GrantScope::Item, subjectId: 1);
    $before = NodeGrant::query()->count();

    accessV1($this->owner, $this->drive)->restrictInheritance($folder->id, InheritanceRestriction::CopyCurrent);

    expect(NodeGrant::query()->count())->toBe($before)
        ->and($folder->refresh()->inherit_grants)->toBeFalse();
});

it('grants the newest version of a role and refuses unknown roles', function (): void {
    $folder = AccessTestHelpers::folder($this->drive, null, 'versioned');
    $grant = accessV1($this->owner, $this->drive)->grant($folder->id, new GrantInput(subject: new Subject('user', 14), role: RoleRegistry::COMMENTER));

    expect($grant->role)->toBe(RoleRegistry::COMMENTER)
        ->and($grant->roleVersion)->toBe(1)
        ->and(fn () => new GrantInput(subject: new Subject('user', 14), role: 'editor'))->toThrow(UnknownRoleException::class);
});

it('stays monotonic: adding a grant never removes an ability', function (): void {
    $parent = AccessTestHelpers::folder($this->drive, null, 'parent');
    $child = AccessTestHelpers::folder($this->drive, $parent, 'child');
    AccessTestHelpers::grant($parent, RoleRegistry::WRITER, subjectId: 15);
    $before = app(AccessResolver::class)->effectiveGrants(AccessTestHelpers::user(15), $child)->allowedBits;

    AccessTestHelpers::grant($child, RoleRegistry::PREVIEWER, scope: GrantScope::Item, subjectId: 15);
    $after = app(AccessResolver::class)->effectiveGrants(AccessTestHelpers::user(15), $child->refresh())->allowedBits;

    expect($after & $before)->toBe($before);
});
