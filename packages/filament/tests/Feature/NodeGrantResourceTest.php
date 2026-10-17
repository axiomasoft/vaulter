<?php

declare(strict_types=1);

use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Repositories\Access\GrantReadRepository;
use Vaulter\Filament\Resources\NodeGrantResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;

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
    DriveBuilder::grant($folder, $owner); // Owner ⇒ may Manage

    $subject = DriveBuilder::user(2);

    NodeGrantResource::grantFromData([
        'node_id' => $folder->getKey(),
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => 2,
        'role' => RoleRegistry::WRITER,
        'scope' => GrantScope::Subtree->value,
        'expires_at' => '',
    ]);

    $grants = app(GrantReadRepository::class)->forNode($folder)->get();

    expect($grants)->toHaveCount(2) // the owner grant + the new writer grant
        ->and($grants->contains(fn (NodeGrant $g): bool => $g->role_key === RoleRegistry::WRITER && (string) $g->subject_id === '2'))
        ->toBeTrue();
});

it('preserves the validated grant form contract at the core Action boundary', function (): void {
    $owner = DriveBuilder::user(41);
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Exact grant');
    DriveBuilder::grant($folder, $owner);

    pullSentNotifications();

    NodeGrantResource::grantFromData([
        'node_id' => $folder->getKey(),
        'subject_type' => 'user',
        'subject_id' => '002',
        'role' => RoleRegistry::WRITER,
        'scope' => GrantScope::Item->value,
        'expires_at' => '2035-01-02 03:04:05',
    ]);

    $grant = app(GrantReadRepository::class)
        ->forNode($folder)
        ->get()
        ->first(fn (NodeGrant $candidate): bool => $candidate->subject_type === 'user');

    expect($grant)->toBeInstanceOf(NodeGrant::class)
        ->and((string) $grant->subject_id)->toBe('2')
        ->and($grant->role_key)->toBe(RoleRegistry::WRITER)
        ->and($grant->role_version)->toBe(1)
        ->and($grant->scope)->toBe(GrantScope::Item)
        ->and($grant->expires_at?->format('Y-m-d H:i:s'))->toBe('2035-01-02 03:04:05')
        ->and((string) $grant->granted_by)->toBe('41');

    $notification = pullSentNotifications()[0] ?? null;

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
        'role' => RoleRegistry::READER,
    ]);

    $grant = app(GrantReadRepository::class)
        ->forNode($folder)
        ->get()
        ->first(fn (NodeGrant $candidate): bool => $candidate->subject_id === 'external-id');

    expect($grant)->toBeInstanceOf(NodeGrant::class)
        ->and($grant->role_key)->toBe(RoleRegistry::READER)
        ->and($grant->scope)->toBe(GrantScope::Subtree)
        ->and($grant->expires_at)->toBeNull();
});

it('rejects a missing grant node with the declared warning', function (): void {
    pullSentNotifications();

    NodeGrantResource::grantFromData([]);

    $notification = pullSentNotifications()[0] ?? null;

    expect(NodeGrant::query()->count())->toBe(0)
        ->and($notification)->toBeArray()
        ->and($notification['title'])->toBe('Grant not saved')
        ->and($notification['body'])->toBe('The selected node no longer exists.')
        ->and($notification['status'])->toBe('warning');
});

it('does not create a grant when the admin may not manage access on the node', function (): void {
    $stranger = DriveBuilder::user(9);
    $this->actingAs($stranger);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Locked');
    // No grant for $stranger ⇒ AccessResolver denies View ⇒ the create no-ops.

    pullSentNotifications();

    NodeGrantResource::grantFromData([
        'node_id' => $folder->getKey(),
        'subject_type' => $stranger->getMorphClass(),
        'subject_id' => 9,
        'role' => RoleRegistry::OWNER,
        'scope' => GrantScope::Subtree->value,
        'expires_at' => '',
    ]);

    $notification = pullSentNotifications()[0] ?? null;

    expect(app(GrantReadRepository::class)->forNode($folder)->count())->toBe(0)
        ->and($notification)->toBeArray()
        ->and($notification['title'])->toBe('Grant not saved')
        ->and($notification['body'])->toBe('You may not manage access on this node.')
        ->and($notification['status'])->toBe('warning');
});

it('prevents an organizer from granting itself owner or revoking another subject', function (): void {
    $organizer = DriveBuilder::user(9);
    $this->actingAs($organizer);
    $drive = DriveBuilder::storage();
    $node = DriveBuilder::folder($drive, 'Organizer');
    $grant = DriveBuilder::grant($node, $organizer, RoleRegistry::ORGANIZER);
    $other = DriveBuilder::grant($node, DriveBuilder::user(10), RoleRegistry::READER);
    pullSentNotifications();

    NodeGrantResource::grantFromData([
        'node_id' => $node->id,
        'subject_type' => $organizer->getMorphClass(),
        'subject_id' => $organizer->id,
        'role' => RoleRegistry::OWNER,
    ]);

    expect($grant->fresh()?->role_key)->toBe(RoleRegistry::ORGANIZER)
        ->and((pullSentNotifications()[0]['status'] ?? null))->toBe('warning')
        ->and(NodeGrantResource::getEloquentQuery()->count())->toBe(0)
        ->and(fn () => NodeGrantResource::revokeRecord($other))->toThrow(PermissionDeniedException::class)
        ->and($other->fresh())->not->toBeNull();
});

it('revokes every grant a subject holds through the core RevokeNodeAccessAction', function (): void {
    $owner = DriveBuilder::user(1);
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Revocable');
    DriveBuilder::grant($folder, $owner);

    $subject = DriveBuilder::user(2);
    DriveBuilder::grant($folder, $subject, RoleRegistry::WRITER);

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
    $grant->forceFill(['role_key' => RoleRegistry::READER])->save();
    expect(fn () => NodeGrantResource::revokeRecord($grant))->toThrow(PermissionDeniedException::class)
        ->and($grant->fresh())->not->toBeNull()
        ->and(NodeGrantResource::getEloquentQuery()->count())->toBe(0);
});
