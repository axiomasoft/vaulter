<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Integration\Tests\TestCase;
use Vaulter\ValueObjects\Access\Actor;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => config()->set('vaulter-drive.access.permission_driver', 'database'));

it('conceals a hidden node before checking the requested ability', function (): void {
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder(storage: $drive, parent: null, name: 'hidden');
    $context = DriveContext::for(drive: $drive, actor: Actor::user(AccessTestHelpers::user()), correlationId: 'test');
    expect(fn () => app(OperationGuard::class)->authorize(context: $context, ability: Ability::Edit, node: $node))->toThrow(NodeNotFoundException::class)
        ->and(fn () => app(OperationGuard::class)->abilities(context: $context, node: $node))->toThrow(NodeNotFoundException::class)
        ->and(fn () => app(OperationGuard::class)->authorizeCreate(context: $context, parent: $node))->toThrow(NodeNotFoundException::class);
});

it('allows View and returns 403 for a visible node without the requested ability', function (): void {
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder(storage: $drive, parent: null, name: 'reader');
    AccessTestHelpers::grant(node: $node, role: RoleRegistry::READER);
    $context = DriveContext::for(drive: $drive, actor: Actor::user(AccessTestHelpers::user()), correlationId: 'test');
    $guard = app(OperationGuard::class);
    $guard->authorize(context: $context, ability: Ability::View, node: $node);
    expect(fn () => $guard->authorize(context: $context, ability: Ability::Edit, node: $node))->toThrow(PermissionDeniedException::class);
    expect($guard->abilities(context: $context, node: $node)->flags)->toMatchArray(['view' => true, 'edit' => false]);
});

it('requires both the workspace gate and parent Create permission', function (): void {
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder(storage: $drive, parent: null, name: 'parent');
    $grant = AccessTestHelpers::grant(node: $node, role: RoleRegistry::READER);
    $context = DriveContext::for(drive: $drive, actor: Actor::user(AccessTestHelpers::user()), correlationId: 'test');
    $guard = app(OperationGuard::class);
    expect(fn () => $guard->authorizeCreate(context: $context, parent: $node))->toThrow(PermissionDeniedException::class);
    app(VaulterDatabase::class)->write(fn () => $grant->forceFill(['role_key' => RoleRegistry::WRITER])->save());
    $guard->authorizeCreate(context: $context, parent: $node);
    $driver = Mockery::mock(PermissionDriver::class);
    $driver->shouldReceive('subjectsFor')->andReturn([Subject::ofModel(AccessTestHelpers::user())]);
    $driver->shouldReceive('override')->andReturn(null);
    $driver->shouldReceive('resolve')->andReturn(null);
    $driver->shouldReceive('canCreateIn')->andReturn(false);
    app()->instance(PermissionDriver::class, $driver);
    config()->set('vaulter-drive.access.permission_drivers.no_create', PermissionDriver::class);
    config()->set('vaulter-drive.access.permission_driver', 'no_create');
    app()->forgetInstance(PermissionDriverManager::class);
    $guard = app(OperationGuard::class);
    expect(fn () => $guard->authorizeCreate(context: $context, parent: $node))->toThrow(PermissionDeniedException::class);
});

it('batches visibility in input order and re-evaluates ACL on every call', function (): void {
    $drive = AccessTestHelpers::storage();
    $a = AccessTestHelpers::folder(storage: $drive, parent: null, name: 'a');
    $b = AccessTestHelpers::folder(storage: $drive, parent: null, name: 'b');
    $hidden = AccessTestHelpers::folder(storage: $drive, parent: null, name: 'hidden');
    $foreign = AccessTestHelpers::folder(storage: AccessTestHelpers::storage(), parent: null, name: 'foreign');
    $grant = AccessTestHelpers::grant(node: $a, role: RoleRegistry::READER);
    AccessTestHelpers::grant(node: $b, role: RoleRegistry::WRITER);
    $context = DriveContext::for(drive: $drive, actor: Actor::user(AccessTestHelpers::user()), correlationId: 'test');
    $guard = app(OperationGuard::class);
    $visible = $guard->visible(context: $context, nodes: (function () use ($b, $foreign, $hidden, $a) {
        yield $b;
        yield $foreign;
        yield $hidden;
        yield $a;
    })());
    expect(array_map(fn ($node) => $node->id, $visible))->toBe([$b->id, $a->id]);
    foreach ($visible as $node) {
        $guard->authorize(context: $context, ability: Ability::View, node: $node);
        expect($guard->abilities(context: $context, node: $node)->flags['view'])->toBeTrue();
        expect($guard->abilities(context: $context, node: $node)->flags)->toBe(array_replace(app(AccessResolver::class)->abilitiesFor(actor: AccessTestHelpers::user(), node: $node), ['share' => false, 'comment' => false]));
    }
    app(VaulterDatabase::class)->write(fn () => $grant->delete());
    expect(fn () => $guard->authorize(context: $context, ability: Ability::View, node: $a))->toThrow(NodeNotFoundException::class)
        ->and(array_map(fn ($node) => $node->id, $guard->visible(context: $context, nodes: [$a, $b])))->toBe([$b->id])
        ->and($guard->visible(context: $context, nodes: []))->toBe([]);
});
