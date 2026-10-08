<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('re-fetches keys and ignores caller attributes relations and connection', function (): void {
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder(storage: $drive, parent: null, name: 'real');
    $forged = clone $node;
    $forged->forceFill(['id' => 'dirty-key', 'name' => 'forged', 'drive_id' => 'foreign']);
    $forged->setConnection('nonexistent');
    $forged->setRelation(relation: 'drive', value: AccessTestHelpers::storage());
    $context = OperationContext::system(drive: $drive, reason: 'test');
    $fresh = app(NodeLocator::class)->find(context: $context, key: $forged);
    expect($fresh->id)->toBe($node->id)->and($fresh->name)->toBe('real')->and($fresh->drive_id)->toBe($drive->id)
        ->and($fresh->relationLoaded('drive'))->toBeFalse();
    expect(app(NodeLocator::class)->find(context: $context, key: (string) $node->id)->id)->toBe($node->id);
});

it('conceals foreign absent and unpersisted nodes', function (): void {
    $drive = AccessTestHelpers::storage();
    $foreign = AccessTestHelpers::folder(storage: AccessTestHelpers::storage(), parent: null, name: 'secret');
    $foreign->drive_id = $drive->id;
    $context = OperationContext::system(drive: $drive, reason: 'test');
    foreach ([$foreign, $foreign->id, 'absent', new Node, ''] as $key) {
        expect(fn () => app(NodeLocator::class)->find(context: $context, key: $key))->toThrow(NodeNotFoundException::class);
    }
});

it('excludes trashed nodes unless the internal trash mode is explicit', function (): void {
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder(storage: $drive, parent: null, name: 'trashed');
    app(VaulterDatabase::class)->write(fn () => $node->forceFill(['trashed_at' => now()])->save());
    $context = OperationContext::system(drive: $drive, reason: 'test');
    expect(fn () => app(NodeLocator::class)->find(context: $context, key: $node->id))->toThrow(NodeNotFoundException::class)
        ->and(app(NodeLocator::class)->find(context: $context, key: $node->id, includeTrashed: true)->trashed_at)->not->toBeNull();
});

it('uses the configured node model', function (): void {
    config()->set('vaulter.models.node', LocatorConfiguredNode::class);
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder(storage: $drive, parent: null, name: 'configured');
    expect(app(NodeLocator::class)->find(context: OperationContext::system(drive: $drive, reason: 'test'), key: $node->id))->toBeInstanceOf(LocatorConfiguredNode::class);
});

it('queries the Vaulter connection while the host default points at a separate empty database', function (): void {
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder(storage: $drive, parent: null, name: 'vaulter-connection');
    config()->set('database.connections.locator_host', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('vaulter.database.connection', 'testing');
    config()->set('database.default', 'locator_host');
    try {
        $fresh = app(NodeLocator::class)->find(context: OperationContext::system(drive: $drive, reason: 'test'), key: $node->id);
        expect($fresh->id)->toBe($node->id)->and($fresh->getConnectionName())->toBe('testing');
    } finally {
        config()->set('database.default', 'testing');
        config()->set('vaulter.database.connection', null);
    }
});

class LocatorConfiguredNode extends Node {}
