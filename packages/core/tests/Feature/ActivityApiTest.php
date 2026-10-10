<?php

declare(strict_types=1);

use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Events\EventType;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The activity feed HTTP surface (§2.9 / M2c-activity, REST v1). The feed is queried
 * by drive/node scope — this IS "recipients resolved at READ" (no notification rows,
 * no per-user fan-out). Both endpoints require Ability::View on the scope and return
 * a scoped, newest-first, cursor-paginated feed of {id, type, node_id, actor,
 * occurred_at, data}.
 */
beforeEach(function (): void {
    // The feed endpoints authorise via the real grant resolver (View on the scope).
    config()->set('vaulter.access.permission_driver', 'database');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

it('returns a storage-scoped feed newest-first to a viewer', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = AccessTestHelpers::folder($storage, null, 'root-grant');
    // A View grant on the storage root opens the storage feed.
    AccessTestHelpers::grant($root, RoleRegistry::READER, scope: GrantScope::Subtree, subjectId: 1);

    // Provisioning fired background rows in this storage scope; isolate the two we
    // assert order on.
    Activity::query()->delete();

    // Seed two rows with a known order (older, then newer).
    $older = activityRow($storage->getKey(), EventType::FolderCreated, 'older');
    $newer = activityRow($storage->getKey(), EventType::NodeRenamed, 'newer');
    $older->forceFill(['created_at' => now()->subMinute()])->save();
    $newer->forceFill(['created_at' => now()])->save();

    // The storage feed is authorised by View on the storage's ROOT node; point the
    // storage at our granted root so the guard passes.
    $storage->forceFill(['root_node_id' => $root->getKey()])->save();

    $response = $this->getJson('api/vaulter/v1/drives/'.$storage->getKey().'/activity')->assertOk();

    $types = array_column($response->json('data'), 'type');

    expect($types[0])->toBe(EventType::NodeRenamed->value)
        ->and($types[1])->toBe(EventType::FolderCreated->value)
        // A drive-targeted entry names no node; its context travels as data.
        ->and($response->json('data.0.node_id'))->toBeNull()
        ->and($response->json('data.0.data'))->toBe(['name' => 'newer']);
});

it('returns a node-scoped feed (only that node target)', function (): void {
    $storage = AccessTestHelpers::storage();
    $nodeA = AccessTestHelpers::folder($storage, null, 'A');
    $nodeB = AccessTestHelpers::folder($storage, null, 'B');
    AccessTestHelpers::grant($nodeA, RoleRegistry::READER, scope: GrantScope::Item, subjectId: 1);

    targetedRow($storage->getKey(), $nodeA, EventType::NodeRenamed);
    targetedRow($storage->getKey(), $nodeB, EventType::NodeRenamed);

    $response = $this->getJson('api/vaulter/v1/nodes/'.$nodeA->getKey().'/activity')->assertOk();

    $targets = array_column($response->json('data'), 'node_id');

    expect($targets)->toBe([(string) $nodeA->getKey()]);
});

it('keeps request network details out of the v1 entry', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'private-details');
    AccessTestHelpers::grant($node, RoleRegistry::READER, scope: GrantScope::Item, subjectId: 1);

    targetedRow($storage->getKey(), $node, EventType::NodeMoved)->forceFill(['ip' => '10.0.0.7', 'user_agent' => 'curl'])->save();

    $entry = $this->getJson('api/vaulter/v1/nodes/'.$node->getKey().'/activity')->assertOk()->json('data.0');

    expect(array_keys($entry))->toBe(['id', 'type', 'node_id', 'actor', 'occurred_at', 'data'])
        ->and($entry['type'])->toBe(EventType::NodeMoved->value)
        ->and($entry['occurred_at'])->toEndWith('Z');
});

it('cursor-paginates the feed', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'paged');
    AccessTestHelpers::grant($node, RoleRegistry::READER, scope: GrantScope::Item, subjectId: 1);

    foreach (range(1, 3) as $ignored) {
        targetedRow($storage->getKey(), $node, EventType::NodeRenamed);
    }

    $response = $this->getJson('api/vaulter/v1/nodes/'.$node->getKey().'/activity?per_page=2')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.next_cursor'))->not->toBeNull();
});

it('403s a node feed for an actor WITHOUT View (opaque404)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'private');
    // No grant for user 1 → no View.
    targetedRow($storage->getKey(), $node, EventType::NodeRenamed);

    $this->getJson('api/vaulter/v1/nodes/'.$node->getKey().'/activity')->assertStatus(404);
});

/**
 * A bare activity row in a storage scope (no target) with a verb + name context.
 */
function activityRow(int|string $storageId, EventType $verb, string $name): Activity
{
    $activity = new Activity;
    $activity->forceFill([
        'drive_id' => $storageId,
        'target_type' => (new Drive)->getMorphClass(),
        'target_id' => $storageId,
        'verb' => $verb->value,
        'context' => ['name' => $name],
        'created_at' => now(),
    ])->save();

    return $activity;
}

/**
 * An activity row targeting a specific node.
 */
function targetedRow(int|string $storageId, Node $node, EventType $verb): Activity
{
    $activity = new Activity;
    $activity->forceFill([
        'drive_id' => $storageId,
        'verb' => $verb->value,
        'target_type' => $node->getMorphClass(),
        'target_id' => $node->getKey(),
        'created_at' => now(),
    ])->save();

    return $activity;
}
