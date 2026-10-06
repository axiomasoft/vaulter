<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\PermissionDriverManager;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Actions\Access\GrantNodeAccessAction;
use Vaulter\Configuration\AccessConfig;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Data\Access\GrantNodeAccessInput;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Exceptions\Access\MissingPermissionDriverException;
use Vaulter\Exceptions\Access\UnenumerableGrantSubjectException;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Operations\OperationContext;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Testing\NullPermissionDriver;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\Access\ProfileAllowDriver;
use Vaulter\Tests\Fixtures\Access\ProfileDenyDriver;
use Vaulter\Tests\Fixtures\Access\ProfileRoleSubjectsDriver;
use Vaulter\Tests\TestCase;
use Vaulter\ValueObjects\Access\OwnerRef;

uses(TestCase::class, RefreshDatabase::class);

function permissionProfiles(): void
{
    config()->set('vaulter.access.permission_driver', 'database');
    config()->set('vaulter.access.permission_drivers.positive', ProfileAllowDriver::class);
    config()->set('vaulter.access.permission_drivers.negative', ProfileDenyDriver::class);
    config()->set('vaulter.access.permission_drivers.roles', ProfileRoleSubjectsDriver::class);
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
    $drive->save();

    return $drive;
}

it('isolates profile drivers across alternating scalar batch explain and create on one resolver', function (): void {
    permissionProfiles();
    $positive = permissionDrive('positive');
    $negative = permissionDrive('negative');
    $a = AccessTestHelpers::folder($positive, null, 'positive');
    $b = AccessTestHelpers::folder($negative, null, 'negative');
    AccessTestHelpers::grant($b, GrantLevel::Owner);
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
    config()->set('vaulter.access.permission_drivers.positive', ProfileDenyDriver::class);
    expect($resolver->can($actor, Ability::View, $a))->toBeFalse()
        ->and($resolver->canCreateIn($actor, $positive))->toBeFalse();
});

it('keeps node-owner decisions local to each batch group and reads changed grants afresh', function (): void {
    permissionProfiles();
    $drive = permissionDrive('positive');
    $a = AccessTestHelpers::folder($drive, null, 'a');
    $b = AccessTestHelpers::folder($drive, null, 'b');
    $a->forceFill(['owner_id' => 1])->save();
    $b->forceFill(['owner_id' => 2])->save();
    $resolver = app(AccessResolver::class);
    $actor = AccessTestHelpers::user();
    expect($resolver->evaluateMany($actor, [$a, $b])[(string) $a->id]->allows(Ability::View))->toBeTrue()
        ->and($resolver->evaluateMany($actor, [$a, $b])[(string) $b->id]->allows(Ability::View))->toBeFalse();
    $ordinary = AccessTestHelpers::folder(permissionDrive('default'), null, 'acl');
    $grant = AccessTestHelpers::grant($ordinary, GrantLevel::Commenter);
    expect($resolver->can($actor, Ability::Comment, $ordinary))->toBeTrue();
    $grant->delete();
    expect($resolver->can($actor, Ability::Comment, $ordinary))->toBeFalse();
});

it('uses each selected subject lane for shared candidates and role grant insertion', function (): void {
    permissionProfiles();
    $roleDrive = permissionDrive('roles');
    $dbDrive = permissionDrive('default');
    $a = AccessTestHelpers::folder($roleDrive, null, 'role-visible');
    $b = AccessTestHelpers::folder($dbDrive, null, 'role-inert');
    foreach ([$a, $b] as $node) {
        AccessTestHelpers::grant($node, GrantLevel::Reader, subjectType: 'role', subjectId: 7);
    }
    $actor = AccessTestHelpers::user();
    $resolver = app(AccessResolver::class);
    expect($resolver->sharedNodeIds($actor))->toContain($a->id)->not->toContain($b->id)
        ->and(app(NodeReadRepository::class)->candidateNodeIds($actor))->toContain($a->id)->not->toContain($b->id)
        ->and(app(NodeReadRepository::class)->sharedWith($actor)->modelKeys())->toContain($a->id)->not->toContain($b->id);
    $data = new GrantInput(subject: new Subject(type: 'role', id: 8), level: GrantLevel::Reader);
    expect(app(GrantNodeAccessAction::class)->execute(OperationContext::system($a->storage()->firstOrFail(), 'fixture'), new GrantNodeAccessInput(node: $a, grant: $data))->subject_id)->toBe(8)
        ->and(fn () => app(GrantNodeAccessAction::class)->execute(OperationContext::system($b->storage()->firstOrFail(), 'fixture'), new GrantNodeAccessInput(node: $b, grant: $data)))->toThrow(UnenumerableGrantSubjectException::class);
});

it('keeps host denial when grants are disabled and preserves raw masks', function (): void {
    permissionProfiles();
    config()->set('vaulter.features.grants', false);
    $node = AccessTestHelpers::folder(permissionDrive('negative'), null, 'denied');
    AccessTestHelpers::grant($node, GrantLevel::Owner, permissions: 31);
    $resolver = app(AccessResolver::class);
    $actor = AccessTestHelpers::user();
    expect($resolver->effectiveGrants($actor, $node)->deniedBits)->toBe(63)
        ->and($resolver->evaluateMany($actor, [$node])[(string) $node->id]->deniedBits)->toBe(63);
    expect(Ability::Comment->satisfiedBy(31))->toBeFalse()
        ->and(GrantLevel::Owner->bits())->toBe(63);
});

it('builds node-blind drive tenant owner and morph-alias coordinates', function (): void {
    $drive = AccessTestHelpers::storage();
    $drive->tenant_id = 'tenant-a';
    $scope = AccessScope::forDrive($drive, new Subject(type: $drive->owner_type, id: 5));
    expect($scope->profile)->toBe($drive->profile)
        ->and($scope->driveId)->toBe($drive->id)
        ->and($scope->driveOwner->type)->toBe($drive->owner_type)
        ->and($scope->tenantId)->toBe('tenant-a')
        ->and($scope->nodeOwner->id)->toBe(5)
        ->and(OwnerRef::ofModel(AccessTestHelpers::user())->type)->toBe(AccessTestHelpers::user()->getMorphClass());
});

it('rejects non-production drivers selected by a profile at boot and resolution', function (): void {
    permissionProfiles();
    config()->set('vaulter.access.permission_drivers.positive', NullPermissionDriver::class);
    $manager = new PermissionDriverManager(app(AccessConfig::class), app(), true);
    expect(fn () => $manager->forProfile('positive'))->toThrow(MissingPermissionDriverException::class);
    app()->detectEnvironment(static fn (): string => 'production');
    expect(fn () => app(ConfigValidator::class)->validate(app(VaulterConfig::class), app()))
        ->toThrow(InvalidConfigurationException::class);
});

it('normalizes level owners without modifying raw stored grants', function (): void {
    permissionProfiles();
    $drive = permissionDrive('default');
    $raw = AccessTestHelpers::folder($drive, null, 'raw31');
    $owner = AccessTestHelpers::folder($drive, null, 'owner31');
    $rawGrant = AccessTestHelpers::grant($raw, GrantLevel::Reader, permissions: 31);
    $ownerGrant = AccessTestHelpers::grant($owner, GrantLevel::Owner, permissions: 31);
    $resolver = app(AccessResolver::class);
    expect($resolver->can(AccessTestHelpers::user(), Ability::Comment, $raw))->toBeFalse()
        ->and($resolver->can(AccessTestHelpers::user(), Ability::Comment, $owner))->toBeTrue()
        ->and($rawGrant->refresh()->permissions)->toBe(31)
        ->and($ownerGrant->refresh()->permissions)->toBe(31);
});
