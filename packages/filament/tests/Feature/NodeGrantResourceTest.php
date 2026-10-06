<?php

declare(strict_types=1);

use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Filament\Resources\NodeGrantResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Repositories\Access\GrantReadRepository;

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

it('preserves the validated grant form contract at the core Action boundary', function (): void {
    $owner = DriveBuilder::user(41);
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Exact grant');
    DriveBuilder::grant($folder, $owner);

    session()->forget('filament.notifications');

    NodeGrantResource::grantFromData([
        'node_id' => $folder->getKey(),
        'subject_type' => 'user',
        'subject_id' => '002',
        'level' => GrantLevel::Writer->value,
        'effect' => Effect::Deny->value,
        'scope' => GrantScope::Item->value,
        'permissions' => '31',
        'expires_at' => '2035-01-02 03:04:05',
    ]);

    $grant = app(GrantReadRepository::class)
        ->forNode($folder)
        ->get()
        ->first(fn (NodeGrant $candidate): bool => $candidate->subject_type === 'user');

    expect($grant)->toBeInstanceOf(NodeGrant::class)
        ->and((string) $grant->subject_id)->toBe('2')
        ->and($grant->level)->toBe(GrantLevel::Writer)
        ->and($grant->effect)->toBe(Effect::Deny)
        ->and($grant->scope)->toBe(GrantScope::Item)
        ->and($grant->permissions)->toBe(31)
        ->and($grant->expires_at?->format('Y-m-d H:i:s'))->toBe('2035-01-02 03:04:05')
        ->and((string) $grant->granted_by)->toBe('41');

    $notification = session('filament.notifications.0');

    expect($notification)->toBeArray()
        ->and($notification['title'])->toBe('Grant saved')
        ->and($notification['status'])->toBe('success');
});

it('applies grant defaults when optional form values are omitted', function (): void {
    $owner = DriveBuilder::user(42);
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Default grant');
    DriveBuilder::grant($folder, $owner);

    NodeGrantResource::grantFromData([
        'node_id' => $folder->getKey(),
        'subject_type' => 'user',
        'subject_id' => 'external-id',
        'level' => GrantLevel::Reader->value,
    ]);

    $grant = app(GrantReadRepository::class)
        ->forNode($folder)
        ->get()
        ->first(fn (NodeGrant $candidate): bool => $candidate->subject_id === 'external-id');

    expect($grant)->toBeInstanceOf(NodeGrant::class)
        ->and($grant->effect)->toBe(Effect::Allow)
        ->and($grant->scope)->toBe(GrantScope::Subtree)
        ->and($grant->permissions)->toBe(0)
        ->and($grant->expires_at)->toBeNull();
});

it('rejects a missing grant node with the declared warning', function (): void {
    session()->forget('filament.notifications');

    NodeGrantResource::grantFromData([]);

    $notification = session('filament.notifications.0');

    expect(NodeGrant::query()->count())->toBe(0)
        ->and($notification)->toBeArray()
        ->and($notification['title'])->toBe('Grant not saved')
        ->and($notification['body'])->toBe('The selected node no longer exists.')
        ->and($notification['status'])->toBe('warning');
});

it('does not create a grant when the admin may not share the node', function (): void {
    $stranger = DriveBuilder::user(9);
    $this->actingAs($stranger);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Locked');
    // No grant for $stranger ⇒ AccessResolver denies Share ⇒ the create no-ops.

    session()->forget('filament.notifications');

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

    $notification = session('filament.notifications.0');

    expect(app(GrantReadRepository::class)->forNode($folder)->count())->toBe(0)
        ->and($notification)->toBeArray()
        ->and($notification['title'])->toBe('Grant not saved')
        ->and($notification['body'])->toBe('You may not manage sharing on this node.')
        ->and($notification['status'])->toBe('warning');
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
    NodeGrantResource::revokeRecord(app(GrantReadRepository::class)->forNode($folder)->where('subject_id', 2)->firstOrFail());

    $remaining = app(GrantReadRepository::class)->forNode($folder)->get();

    expect($remaining)->toHaveCount(1)
        ->and((string) $remaining->first()->subject_id)->toBe('1');
});

it('rechecks a reader and foreign membership inside actual grant revoke', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);
    $drive = DriveBuilder::storage();
    $node = DriveBuilder::folder($drive);
    $grant = DriveBuilder::grant($node, $owner);
    $grant->forceFill(['level' => GrantLevel::Reader])->save();
    expect(fn () => NodeGrantResource::revokeRecord($grant))->toThrow(PermissionDeniedException::class)
        ->and($grant->fresh())->not->toBeNull()
        ->and(NodeGrantResource::getEloquentQuery()->count())->toBe(0);
});
