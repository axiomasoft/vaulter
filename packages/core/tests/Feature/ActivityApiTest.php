<?php

declare(strict_types=1);

use Vaulter\Enums\ActivityVerb;
use Vaulter\Enums\GrantLevel;
use Vaulter\Enums\GrantScope;
use Vaulter\Models\Activity;
use Vaulter\Models\Node;
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
    config()->set('vaulter.drivers.permission', 'database');
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
    $older = activityRow($storage->getKey(), ActivityVerb::NodeCreated, 'older');
    $newer = activityRow($storage->getKey(), ActivityVerb::NodeRenamed, 'newer');
    $older->forceFill(['created_at' => now()->subMinute()])->save();
    $newer->forceFill(['created_at' => now()])->save();

    // The storage feed is authorised by View on the storage's ROOT node; point the
    // storage at our granted root so the guard passes.
    $storage->forceFill(['root_node_id' => $root->getKey()])->save();

    $response = $this->getJson('api/vaulter/storages/'.$storage->getKey().'/activity')->assertOk();

    $verbs = array_column($response->json('data'), 'verb');

    expect($verbs[0])->toBe(ActivityVerb::NodeRenamed->value)
        ->and($verbs[1])->toBe(ActivityVerb::NodeCreated->value);
});

it('returns a node-scoped feed (only that node target)', function (): void {
    $storage = AccessTestHelpers::storage();
    $nodeA = AccessTestHelpers::folder($storage, null, 'A');
    $nodeB = AccessTestHelpers::folder($storage, null, 'B');
    AccessTestHelpers::grant($nodeA, GrantLevel::Reader, scope: GrantScope::Item, subjectId: 1);

    targetedRow($storage->getKey(), $nodeA, ActivityVerb::NodeRenamed);
    targetedRow($storage->getKey(), $nodeB, ActivityVerb::NodeRenamed);

    $response = $this->getJson('api/vaulter/nodes/'.$nodeA->getKey().'/activity')->assertOk();

    $targets = array_column($response->json('data'), 'target_id');

    expect($targets)->toHaveCount(1)
        ->and((string) $targets[0])->toBe((string) $nodeA->getKey());
});

it('filters a node feed by verb', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'filtered');
    AccessTestHelpers::grant($node, GrantLevel::Reader, scope: GrantScope::Item, subjectId: 1);

    targetedRow($storage->getKey(), $node, ActivityVerb::NodeRenamed);
    targetedRow($storage->getKey(), $node, ActivityVerb::NodeMoved);

    $response = $this->getJson('api/vaulter/nodes/'.$node->getKey().'/activity?verb=node.moved')->assertOk();

    $verbs = array_column($response->json('data'), 'verb');

    expect($verbs)->toBe([ActivityVerb::NodeMoved->value]);
});

it('cursor-paginates the feed', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'paged');
    AccessTestHelpers::grant($node, GrantLevel::Reader, scope: GrantScope::Item, subjectId: 1);

    foreach (range(1, 3) as $ignored) {
        targetedRow($storage->getKey(), $node, ActivityVerb::NodeRenamed);
    }

    $response = $this->getJson('api/vaulter/nodes/'.$node->getKey().'/activity?per_page=2')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.next_cursor'))->not->toBeNull();
});

it('403s a node feed for an actor WITHOUT View', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'private');
    // No grant for user 1 → no View.
    targetedRow($storage->getKey(), $node, ActivityVerb::NodeRenamed);

    $this->getJson('api/vaulter/nodes/'.$node->getKey().'/activity')->assertStatus(403);
});

/**
 * A bare activity row in a storage scope (no target) with a verb + name context.
 */
function activityRow(int|string $storageId, ActivityVerb $verb, string $name): Activity
{
    $activity = new Activity;
    $activity->forceFill([
        'storage_id' => $storageId,
        'verb' => $verb->value,
        'context' => ['name' => $name],
        'created_at' => now(),
    ])->save();

    return $activity;
}

/**
 * An activity row targeting a specific node.
 */
function targetedRow(int|string $storageId, Node $node, ActivityVerb $verb): Activity
{
    $activity = new Activity;
    $activity->forceFill([
        'storage_id' => $storageId,
        'verb' => $verb->value,
        'target_type' => $node->getMorphClass(),
        'target_id' => $node->getKey(),
        'created_at' => now(),
    ])->save();

    return $activity;
}
