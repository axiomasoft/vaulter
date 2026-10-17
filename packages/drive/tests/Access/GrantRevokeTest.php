<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Actions\Access\GrantNodeAccessAction;
use Vaulter\Drive\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\Access\GrantNodeAccessInput;
use Vaulter\Drive\Data\Access\RevokeGrantInput;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Events\Access\NodeGrantCreated;
use Vaulter\Drive\Events\Access\NodeGrantRevoked;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

beforeEach(function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
});

it('grants access through the action and dispatches NodeGrantCreated', function (): void {
    Event::fake([NodeGrantCreated::class]);

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'shared');
    $actor = AccessTestHelpers::user();

    $grant = app(GrantNodeAccessAction::class)->execute(context: DriveContext::system($folder->storage()->firstOrFail(), 'fixture'), input: new GrantNodeAccessInput(node: $folder, grant: new GrantInput(
        subject: Subject::ofModel($actor),
        role: RoleRegistry::WRITER,
        scope: GrantScope::Subtree,
    )));

    expect($grant->role_key)->toBe(RoleRegistry::WRITER)
        ->and(app(AccessResolver::class)->can($actor, Ability::Edit, $folder))->toBeTrue();

    Event::assertDispatched(NodeGrantCreated::class);
});

it('is idempotent on the grant-unique key (re-grant updates, not duplicates)', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'idem');
    $subject = Subject::ofModel(AccessTestHelpers::user());

    $action = app(GrantNodeAccessAction::class);

    $action->execute(DriveContext::system($folder->storage()->firstOrFail(), 'fixture'), new GrantNodeAccessInput(node: $folder, grant: new GrantInput(subject: $subject, role: RoleRegistry::READER)));
    $action->execute(DriveContext::system($folder->storage()->firstOrFail(), 'fixture'), new GrantNodeAccessInput(node: $folder, grant: new GrantInput(subject: $subject, role: RoleRegistry::OWNER)));

    expect(NodeGrant::query()->where('node_id', $folder->id)->count())->toBe(1)
        ->and(NodeGrant::query()->where('node_id', $folder->id)->first()->role_key)->toBe(RoleRegistry::OWNER);
});

it('revokes a subject and stops authorising', function (): void {
    Event::fake([NodeGrantRevoked::class]);

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'revoke');
    $actor = AccessTestHelpers::user();
    $subject = Subject::ofModel($actor);

    AccessTestHelpers::grant($folder, RoleRegistry::WRITER, scope: GrantScope::Item);
    expect(app(AccessResolver::class)->can($actor, Ability::View, $folder))->toBeTrue();

    $revoked = app(RevokeNodeAccessAction::class)->execute(context: DriveContext::system($storage, 'fixture'), input: new RevokeGrantInput(node: $folder, grant: NodeGrant::query()->where('node_id', $folder->id)->firstOrFail()));

    expect($revoked)->toBe(1)
        ->and(app(AccessResolver::class)->can($actor, Ability::View, $folder))->toBeFalse();

    Event::assertDispatched(NodeGrantRevoked::class);
});

it('rejects a missing grant without an event', function (): void {
    Event::fake([NodeGrantRevoked::class]);

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'empty');

    $missing = new NodeGrant;
    $missing->forceFill(['id' => 'missing']);
    expect(fn () => app(RevokeNodeAccessAction::class)->execute(context: DriveContext::system($storage, 'fixture'), input: new RevokeGrantInput(node: $folder, grant: $missing)))->toThrow(NodeNotFoundException::class);
    Event::assertNotDispatched(NodeGrantRevoked::class);
});

it('carries a time-boxed expiry onto the stored grant', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'timed');
    $expires = Carbon::now()->addDay()->startOfSecond();

    $grant = app(GrantNodeAccessAction::class)->execute(context: DriveContext::system($folder->storage()->firstOrFail(), 'fixture'), input: new GrantNodeAccessInput(node: $folder, grant: new GrantInput(
        subject: Subject::ofModel(AccessTestHelpers::user()),
        role: RoleRegistry::READER,
        expiresAt: $expires,
    )));

    expect($grant->expires_at->equalTo($expires))->toBeTrue();
});
