<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Actions\Access\GrantNodeAccessAction;
use Vaulter\Drive\Configuration\AccessConfig;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\Access\GrantNodeAccessInput;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\Permission;
use Vaulter\Drive\Exceptions\Access\MissingPermissionDriverException;
use Vaulter\Drive\Exceptions\Access\UnenumerableGrantSubjectException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Fixtures\Access\ProfileAllowDriver;
use Vaulter\Drive\Testing\Fixtures\Access\ProfileDenyDriver;
use Vaulter\Drive\Testing\Fixtures\Access\ProfileRoleSubjectsDriver;
use Vaulter\Drive\Testing\NullPermissionDriver;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Tests\TestCase;
use Vaulter\ValueObjects\Access\OwnerRef;

uses(TestCase::class, RefreshDatabase::class);

function permissionProfiles(): void
{
    config()->set('vaulter-drive.access.permission_driver', 'database');
    config()->set('vaulter-drive.access.permission_drivers.positive', ProfileAllowDriver::class);
    config()->set('vaulter-drive.access.permission_drivers.negative', ProfileDenyDriver::class);
    config()->set('vaulter-drive.access.permission_drivers.roles', ProfileRoleSubjectsDriver::class);
    $registry = new ProfileRegistry;
    foreach ([(new Profile)->key('default'), (new Profile)->key('positive')->permissionDriver('positive'), (new Profile)->key('negative')->permissionDriver('negative'), (new Profile)->key('roles')->permissionDriver('roles')] as $profile) {
        $registry->register($profile);
    }
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
}

function permissionDrive(string $profile): Drive
{
    $drive = AccessTestHelpers::storage();
    $drive->profile = $profile;
    app(VaulterDatabase::class)->write(fn () => $drive->save());

    return $drive;
}

it('isolates profile drivers across alternating scalar batch explain and create on one resolver', function (): void {
    permissionProfiles();
    $positive = permissionDrive('positive');
    $negative = permissionDrive('negative');
    $a = AccessTestHelpers::folder($positive, null, 'positive');
    $b = AccessTestHelpers::folder($negative, null, 'negative');
    AccessTestHelpers::grant($b, RoleRegistry::OWNER);
    $actor = AccessTestHelpers::user();
    $resolver = app(AccessResolver::class);
    foreach ([$a, $b, $a, $b] as $node) {
        $allowed = $node->drive_id === $positive->id;
        expect($resolver->can($actor, Ability::Comment, $node))->toBe($allowed)
            ->and($resolver->explain($actor, Ability::Comment, $node)->allowed)->toBe($allowed)
            ->and($resolver->canCreateIn($actor, $node->storage))->toBe($allowed);
    }
    $sets = $resolver->evaluateMany($actor, [$a, $b]);
    foreach ([$a, $b] as $node) {
        foreach (Ability::cases() as $ability) {
            expect($sets[(string) $node->id]->allows($ability))->toBe($resolver->can($actor, $ability, $node));
        }
    }
    config()->set('vaulter-drive.access.permission_drivers.positive', ProfileDenyDriver::class);
    expect($resolver->can($actor, Ability::View, $a))->toBeFalse()
        ->and($resolver->canCreateIn($actor, $positive))->toBeFalse();
});

it('keeps per-drive override decisions local to each batch group and reads changed grants afresh', function (): void {
    permissionProfiles();
    $a = AccessTestHelpers::folder(permissionDrive('positive'), null, 'a');
    $blocked = permissionDrive('positive');
    $b = AccessTestHelpers::folder($blocked, null, 'b');
    ProfileAllowDriver::$blocked = [(string) $blocked->id];
    $resolver = app(AccessResolver::class);
    $actor = AccessTestHelpers::user();
    expect($resolver->evaluateMany($actor, [$a, $b])[(string) $a->id]->allows(Ability::View))->toBeTrue()
        ->and($resolver->evaluateMany($actor, [$a, $b])[(string) $b->id]->allows(Ability::View))->toBeFalse();
    $ordinary = AccessTestHelpers::folder(permissionDrive('default'), null, 'acl');
    $grant = AccessTestHelpers::grant($ordinary, RoleRegistry::COMMENTER);
    expect($resolver->can($actor, Ability::Comment, $ordinary))->toBeTrue();
    app(VaulterDatabase::class)->write(fn () => $grant->delete());
    expect($resolver->can($actor, Ability::Comment, $ordinary))->toBeFalse();
    ProfileAllowDriver::$blocked = [];
});

it('uses each selected subject lane for shared candidates and role grant insertion', function (): void {
    permissionProfiles();
    $roleDrive = permissionDrive('roles');
    $dbDrive = permissionDrive('default');
    $a = AccessTestHelpers::folder($roleDrive, null, 'role-visible');
    $b = AccessTestHelpers::folder($dbDrive, null, 'role-inert');
    foreach ([$a, $b] as $node) {
        AccessTestHelpers::grant($node, RoleRegistry::READER, subjectType: 'role', subjectId: 7);
    }
    $actor = AccessTestHelpers::user();
    $resolver = app(AccessResolver::class);
    expect($resolver->sharedNodeIds($actor))->toContain($a->id)->not->toContain($b->id)
        ->and(app(NodeReadRepository::class)->candidateNodeIds($actor))->toContain($a->id)->not->toContain($b->id)
        ->and(app(NodeReadRepository::class)->sharedWith($actor)->modelKeys())->toContain($a->id)->not->toContain($b->id);
    $data = new GrantInput(subject: new Subject(type: 'role', id: 8), role: RoleRegistry::READER);
    expect(app(GrantNodeAccessAction::class)->execute(DriveContext::system($a->storage()->firstOrFail(), 'fixture'), new GrantNodeAccessInput(node: $a, grant: $data))->subject_id)->toBe(8)
        ->and(fn () => app(GrantNodeAccessAction::class)->execute(DriveContext::system($b->storage()->firstOrFail(), 'fixture'), new GrantNodeAccessInput(node: $b, grant: $data)))->toThrow(UnenumerableGrantSubjectException::class);
});

it('keeps the host block when grants are disabled', function (): void {
    permissionProfiles();
    config()->set('vaulter-drive.features.grants', false);
    $node = AccessTestHelpers::folder(permissionDrive('negative'), null, 'denied');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER);
    $resolver = app(AccessResolver::class);
    $actor = AccessTestHelpers::user();
    expect($resolver->effectiveGrants($actor, $node)->superDeny)->toBeTrue()
        ->and($resolver->evaluateMany($actor, [$node])[(string) $node->id]->superDeny)->toBeTrue()
        ->and($resolver->can($actor, Ability::View, $node))->toBeFalse();
    expect(Ability::Comment->satisfiedBy(Ability::View->bits() | Ability::Download->bits()))->toBeFalse()
        ->and((new RoleRegistry)->latest(RoleRegistry::OWNER)->bits)->toBe(Permission::all());
});

it('builds node-blind drive tenant owner and morph-alias coordinates', function (): void {
    $drive = AccessTestHelpers::storage();
    $drive->tenant_id = 'tenant-a';
    $scope = AccessScope::forDrive($drive);
    expect($scope->profile)->toBe($drive->profile)
        ->and($scope->driveId)->toBe($drive->id)
        ->and($scope->driveOwner->type)->toBe($drive->owner_type)
        ->and($scope->tenantId)->toBe('tenant-a')
        ->and(OwnerRef::ofModel(AccessTestHelpers::user())->type)->toBe(AccessTestHelpers::user()->getMorphClass());
});

it('rejects non-production drivers selected by a profile at boot and resolution', function (): void {
    permissionProfiles();
    config()->set('vaulter-drive.access.permission_drivers.positive', NullPermissionDriver::class);
    $manager = new PermissionDriverManager(app(AccessConfig::class), app(), true);
    expect(fn () => $manager->forProfile('positive'))->toThrow(MissingPermissionDriverException::class);
    app()->detectEnvironment(static fn (): string => 'production');
    expect(fn () => app(ConfigValidator::class)->validate(app(VaulterConfig::class), app()))
        ->toThrow(InvalidConfigurationException::class);
});

it('resolves a grant by its stored role and version without rewriting it', function (): void {
    permissionProfiles();
    $drive = permissionDrive('default');
    $reader = AccessTestHelpers::folder($drive, null, 'reader');
    $owner = AccessTestHelpers::folder($drive, null, 'owner');
    $readerGrant = AccessTestHelpers::grant($reader, RoleRegistry::READER);
    $ownerGrant = AccessTestHelpers::grant($owner, RoleRegistry::OWNER);
    $resolver = app(AccessResolver::class);
    expect($resolver->can(AccessTestHelpers::user(), Ability::Comment, $reader))->toBeFalse()
        ->and($resolver->can(AccessTestHelpers::user(), Ability::Comment, $owner))->toBeTrue()
        ->and($readerGrant->refresh()->role_key)->toBe(RoleRegistry::READER)
        ->and($readerGrant->role_version)->toBe(1)
        ->and($ownerGrant->refresh()->role()->is((new RoleRegistry)->get(RoleRegistry::OWNER, 1)))->toBeTrue();
});
