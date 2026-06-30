<?php

declare(strict_types=1);

use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Enums\Effect;
use Vaulter\Enums\GrantLevel;
use Vaulter\Enums\GrantScope;
use Vaulter\Filament\Resources\NodeGrantResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Models\NodeGrant;
use Vaulter\Repositories\GrantReadRepository;

it('resolves the configured grant model (swappable)', function (): void {
    expect(NodeGrantResource::getModel())->toBe(NodeGrant::class);
});

it('is not creatable from a flat form (grants come from the core Action)', function (): void {
    expect(NodeGrantResource::canCreate())->toBeFalse();
});

it('creates a grant through the core GrantNodeAccessAction', function (): void {
    $owner = DriveBuilder::user(1);
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Shared');
    DriveBuilder::grant($folder, $owner); // Owner ⇒ may Share

    $subject = DriveBuilder::user(2);

    NodeGrantResource::grantFromData([
        'node_id' => $folder->getKey(),
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => 2,
        'level' => GrantLevel::Writer->value,
        'effect' => Effect::Allow->value,
        'scope' => GrantScope::Subtree->value,
        'permissions' => 0,
        'expires_at' => '',
    ]);

    $grants = app(GrantReadRepository::class)->forNode($folder)->get();

    expect($grants)->toHaveCount(2) // the owner grant + the new writer grant
        ->and($grants->contains(fn (NodeGrant $g): bool => $g->level === GrantLevel::Writer && (string) $g->subject_id === '2'))
        ->toBeTrue();
});

it('does not create a grant when the admin may not share the node', function (): void {
    $stranger = DriveBuilder::user(9);
    $this->actingAs($stranger);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Locked');
    // No grant for $stranger ⇒ AccessResolver denies Share ⇒ the create no-ops.

    NodeGrantResource::grantFromData([
        'node_id' => $folder->getKey(),
        'subject_type' => $stranger->getMorphClass(),
        'subject_id' => 9,
        'level' => GrantLevel::Owner->value,
        'effect' => Effect::Allow->value,
        'scope' => GrantScope::Subtree->value,
        'permissions' => 0,
        'expires_at' => '',
    ]);

    expect(app(GrantReadRepository::class)->forNode($folder)->count())->toBe(0);
});

it('revokes every grant a subject holds through the core RevokeNodeAccessAction', function (): void {
    $owner = DriveBuilder::user(1);
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Revocable');
    DriveBuilder::grant($folder, $owner);

    $subject = DriveBuilder::user(2);
    DriveBuilder::grant($folder, $subject, GrantLevel::Writer);

    expect(app(GrantReadRepository::class)->forNode($folder)->count())->toBe(2);

    // The row revoke action calls exactly this Action.
    app(RevokeNodeAccessAction::class)->execute(
        node: $folder,
        subject: Subject::ofModel($subject),
        actorId: $owner->getKey(),
    );

    $remaining = app(GrantReadRepository::class)->forNode($folder)->get();

    expect($remaining)->toHaveCount(1)
        ->and((string) $remaining->first()->subject_id)->toBe('1');
});
