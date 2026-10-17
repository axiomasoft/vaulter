<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Profiles\UploadPolicy;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\Tests\TestCase;
use Vaulter\ValueObjects\Access\Actor;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    config()->set('vaulter.features.share_links', true);
    config()->set('vaulter-documents.comments.enabled', true);
});

function guardProfile(Profile $profile): void
{
    $registry = new ProfileRegistry;
    $registry->register($profile);
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
}

it('SEC-R14 user and system both obey frozen share policy after user View', function (): void {
    guardProfile((new Profile)->key('default')->shareLinks(false)->comments(false));
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($drive, null, 'visible');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER);
    $guard = app(OperationGuard::class);
    $user = DriveContext::for($drive, Actor::user(AccessTestHelpers::user()), 'profile-test');
    $system = DriveContext::system($drive, 'maintenance');
    foreach ([$user, $system] as $context) {
        expect(fn () => $guard->authorize($context, Ability::Share, $node))->toThrow(ProfileRestrictionException::class);
        expect($guard->abilities($context, $node)->flags)->toMatchArray(['view' => true, 'share' => false, 'comment' => false]);
        expect($guard->visible($context, [$node]))->toHaveCount(1);
        $guard->authorizeCreate($context, $node);
    }
    $hidden = AccessTestHelpers::folder($drive, null, 'hidden');
    expect(fn () => $guard->authorize($user, Ability::Share, $hidden))->toThrow(NodeNotFoundException::class);
});

it('SEC-R14 system bypasses only ACL and never the owner policy on any guard surface', function (): void {
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($drive, null, 'existing');
    guardProfile((new Profile)->key('default')->owners(TestUser::class));
    $context = DriveContext::system($drive, 'maintenance');
    $guard = app(OperationGuard::class);
    expect(fn () => $guard->authorize($context, Ability::View, $node))->toThrow(ProfileRestrictionException::class)
        ->and(fn () => $guard->authorizeCreate($context, $node))->toThrow(ProfileRestrictionException::class)
        ->and($guard->visible($context, [$node]))->toBe([])
        ->and(array_values($guard->abilities($context, $node)->flags))->each->toBeFalse();
});

it('SEC-R14 allows system denied by ACL when profile allows and keeps foreign scope hidden', function (): void {
    guardProfile((new Profile)->key('default')->shareLinks());
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($drive, null, 'own');
    $foreign = AccessTestHelpers::folder(AccessTestHelpers::storage(), null, 'foreign');
    $context = DriveContext::system($drive, 'maintenance');
    $guard = app(OperationGuard::class);
    $guard->authorize($context, Ability::Share, $node);
    expect(fn () => $guard->authorize($context, Ability::Share, $foreign))->toThrow(NodeNotFoundException::class)
        ->and(fn () => $guard->authorizeCreate($context, $foreign))->toThrow(NodeNotFoundException::class)
        ->and(fn () => $guard->abilities($context, $foreign))->toThrow(NodeNotFoundException::class)
        ->and($guard->visible($context, [$node, $foreign]))->toHaveCount(1);
    // A caller's forged context profile is not authoritative.
    $context->drive->profile = 'unknown';
    $guard->authorize($context, Ability::Share, $node);
});

it('SEC-R14 purge and metadata do not require upload MIME scan or public egress', function (): void {
    guardProfile((new Profile)->key('default')->shareLinks(false)->comments(false)
        ->uploads(new UploadPolicy(maxFileSize: 0, allowedMimeTypes: ['image/png'], requireScan: true)));
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($drive, null, 'cleanup');
    $context = DriveContext::system($drive, 'purge infected');
    app(OperationGuard::class)->authorize($context, Ability::Delete, $node, OperationKind::Purge);
    app(OperationGuard::class)->authorize($context, Ability::View, $node);
    expect(app(OperationGuard::class)->visible($context, [$node]))->toHaveCount(1);
});

it('SEC-R14 disabling grants removes scalar and batch ACL contributions without opening access', function (): void {
    guardProfile((new Profile)->key('default')->grants(false));
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($drive, null, 'grant-only');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER);
    $user = DriveContext::for($drive, Actor::user(AccessTestHelpers::user()), 'no-grants');
    $guard = app(OperationGuard::class);
    expect(fn () => $guard->authorize($user, Ability::View, $node))->toThrow(NodeNotFoundException::class)
        ->and($guard->visible($user, [$node]))->toBe([]);
    config()->set('vaulter.access.permission_driver', 'null');
    // A host-role override remains independent from the disabled grant lane.
    $guard = app(OperationGuard::class);
    $guard->authorize($user, Ability::View, $node);
});
