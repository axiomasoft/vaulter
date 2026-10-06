<?php

declare(strict_types=1);

use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Events\EventType;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The activity feed HTTP surface (§2.9 / M2c-activity). The feed is queried by
 * storage/node scope — this IS "recipients resolved at READ" (no notification rows,
 * no per-user fan-out). Both endpoints require Ability::View on the scope and return
 * a scoped, newest-first, cursor-paginated feed.
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
    AccessTestHelpers::grant($root, GrantLevel::Reader, scope: GrantScope::Subtree, subjectId: 1);

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

    $response = $this->getJson('api/vaulter/storages/'.$storage->getKey().'/activity')->assertOk();

    $verbs = array_column($response->json('data'), 'verb');

    expect($verbs[0])->toBe(EventType::NodeRenamed->value)
        ->and($verbs[1])->toBe(EventType::FolderCreated->value);
});

it('returns a node-scoped feed (only that node target)', function (): void {
    $storage = AccessTestHelpers::storage();
    $nodeA = AccessTestHelpers::folder($storage, null, 'A');
    $nodeB = AccessTestHelpers::folder($storage, null, 'B');
    AccessTestHelpers::grant($nodeA, GrantLevel::Reader, scope: GrantScope::Item, subjectId: 1);

    targetedRow($storage->getKey(), $nodeA, EventType::NodeRenamed);
    targetedRow($storage->getKey(), $nodeB, EventType::NodeRenamed);

    $response = $this->getJson('api/vaulter/nodes/'.$nodeA->getKey().'/activity')->assertOk();

    $targets = array_column($response->json('data'), 'target_id');

    expect($targets)->toHaveCount(1)
        ->and((string) $targets[0])->toBe((string) $nodeA->getKey());
});

it('filters a node feed by verb', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'filtered');
    AccessTestHelpers::grant($node, GrantLevel::Reader, scope: GrantScope::Item, subjectId: 1);

    targetedRow($storage->getKey(), $node, EventType::NodeRenamed);
    targetedRow($storage->getKey(), $node, EventType::NodeMoved);

    $response = $this->getJson('api/vaulter/nodes/'.$node->getKey().'/activity?verb=node.moved')->assertOk();

    $verbs = array_column($response->json('data'), 'verb');

    expect($verbs)->toBe([EventType::NodeMoved->value]);
});

it('cursor-paginates the feed', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'paged');
    AccessTestHelpers::grant($node, GrantLevel::Reader, scope: GrantScope::Item, subjectId: 1);

    foreach (range(1, 3) as $ignored) {
        targetedRow($storage->getKey(), $node, EventType::NodeRenamed);
    }

    $response = $this->getJson('api/vaulter/nodes/'.$node->getKey().'/activity?per_page=2')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.next_cursor'))->not->toBeNull();
});

it('403s a node feed for an actor WITHOUT View', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'private');
    // No grant for user 1 → no View.
    targetedRow($storage->getKey(), $node, EventType::NodeRenamed);

    $this->getJson('api/vaulter/nodes/'.$node->getKey().'/activity')->assertStatus(403);
});

/**
 * A bare activity row in a storage scope (no target) with a verb + name context.
 */
function activityRow(int|string $storageId, EventType $verb, string $name): Activity
{
    $activity = new Activity;
    $activity->forceFill([
        'drive_id' => $storageId,
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
