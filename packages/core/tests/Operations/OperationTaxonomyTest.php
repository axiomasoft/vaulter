<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('SEC-R14 system bypasses ACL within scope for each public operation category', function (OperationKind $kind, Ability $ability): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder(storage: $drive, parent: null, name: 'own');
    $context = DriveContext::system(drive: $drive, reason: 'maintenance');
    app(OperationGuard::class)->authorize(context: $context, ability: $ability, node: $node, operation: $kind);
    expect($context->actor->ref()->reason)->toBe('maintenance');
})->with(['metadata' => [OperationKind::Metadata, Ability::View], 'mutation' => [OperationKind::Mutation, Ability::Edit], 'egress seam' => [OperationKind::ContentEgress, Ability::Download], 'purge' => [OperationKind::Purge, Ability::Delete]]);

it('SEC-R14 foreign locator and every system guard surface reject forged scope', function (): void {
    $drive = AccessTestHelpers::storage();
    $foreign = AccessTestHelpers::folder(storage: AccessTestHelpers::storage(), parent: null, name: 'foreign');
    $foreign->drive_id = $drive->id;
    $context = DriveContext::system(drive: $drive, reason: 'maintenance');
    $guard = app(OperationGuard::class);
    expect(fn () => app(NodeLocator::class)->find(context: $context, key: $foreign))->toThrow(NodeNotFoundException::class);
    foreach (OperationKind::cases() as $kind) {
        expect(fn () => $guard->authorize(context: $context, ability: Ability::Delete, node: $foreign, operation: $kind))->toThrow(NodeNotFoundException::class);
    }
    expect(fn () => $guard->authorizeCreate(context: $context, parent: $foreign))->toThrow(NodeNotFoundException::class)
        ->and(fn () => $guard->abilities(context: $context, node: $foreign))->toThrow(NodeNotFoundException::class)
        ->and($guard->visible(context: $context, nodes: [$foreign]))->toBe([]);
});

it('SEC-R14 metadata and purge do not invent upload or scanner dependencies', function (): void {
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder(storage: $drive, parent: null, name: 'own');
    $context = DriveContext::system(drive: $drive, reason: 'maintenance');
    $guard = app(OperationGuard::class);
    $guard->authorizeCreate(context: $context, parent: $node);
    expect($guard->abilities(context: $context, node: $node)->flags)->toMatchArray(['view' => true, 'create' => true, 'delete' => true, 'share' => false, 'comment' => false]);
    expect($guard->visible(context: $context, nodes: [$node])[0]->id)->toBe($node->id)
        ->and(array_column(OperationKind::cases(), 'name'))->toBe(['Metadata', 'Mutation', 'ContentEgress', 'Purge']);
});
