<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Actions\Access\GrantNodeAccessAction;
use Vaulter\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Data\Access\GrantNodeAccessData;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Tests\Access\AccessTestHelpers;

beforeEach(function (): void {
    config()->set('vaulter.drivers.permission', 'database');
});

it('grants access through the action and dispatches NodeGrantCreated', function (): void {
    Event::fake([NodeGrantCreated::class]);

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'shared');
    $actor = AccessTestHelpers::user();

    $grant = app(GrantNodeAccessAction::class)->execute(
        node: $folder,
        data: new GrantNodeAccessData(
            subject: Subject::ofModel($actor),
            level: GrantLevel::Writer,
            scope: GrantScope::Subtree,
        ),
    );

    expect($grant->level)->toBe(GrantLevel::Writer)
        ->and(app(AccessResolver::class)->can($actor, Ability::Edit, $folder))->toBeTrue();

    Event::assertDispatched(NodeGrantCreated::class);
});

it('is idempotent on the grant-unique key (re-grant updates, not duplicates)', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'idem');
    $subject = Subject::ofModel(AccessTestHelpers::user());

    $action = app(GrantNodeAccessAction::class);

    $action->execute($folder, new GrantNodeAccessData(subject: $subject, level: GrantLevel::Reader));
    $action->execute($folder, new GrantNodeAccessData(subject: $subject, level: GrantLevel::Owner));

    expect(NodeGrant::query()->where('node_id', $folder->id)->count())->toBe(1)
        ->and(NodeGrant::query()->where('node_id', $folder->id)->first()->level)->toBe(GrantLevel::Owner);
});

it('revokes a subject and stops authorising', function (): void {
    Event::fake([NodeGrantRevoked::class]);

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'revoke');
    $actor = AccessTestHelpers::user();
    $subject = Subject::ofModel($actor);

    AccessTestHelpers::grant($folder, GrantLevel::Writer, scope: GrantScope::Item);
    expect(app(AccessResolver::class)->can($actor, Ability::View, $folder))->toBeTrue();

    $revoked = app(RevokeNodeAccessAction::class)->execute(node: $folder, subject: $subject);

    expect($revoked)->toBe(1)
        ->and(app(AccessResolver::class)->can($actor, Ability::View, $folder))->toBeFalse();

    Event::assertDispatched(NodeGrantRevoked::class);
});

it('no-ops (no event) when revoking a subject with no grants', function (): void {
    Event::fake([NodeGrantRevoked::class]);

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'empty');

    $revoked = app(RevokeNodeAccessAction::class)->execute(
        node: $folder,
        subject: Subject::ofModel(AccessTestHelpers::user()),
    );

    expect($revoked)->toBe(0);
    Event::assertNotDispatched(NodeGrantRevoked::class);
});

it('carries a time-boxed expiry onto the stored grant', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'timed');
    $expires = Carbon::now()->addDay()->startOfSecond();

    $grant = app(GrantNodeAccessAction::class)->execute(
        node: $folder,
        data: new GrantNodeAccessData(
            subject: Subject::ofModel(AccessTestHelpers::user()),
            level: GrantLevel::Reader,
            effect: Effect::Allow,
            expiresAt: $expires,
        ),
    );

    expect($grant->expires_at->equalTo($expires))->toBeTrue();
});
