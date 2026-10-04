<?php

declare(strict_types=1);

namespace Vaulter\Tests\Concerns;

use Illuminate\Support\Facades\Storage;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Support\Config;
use Vaulter\Tests\Access\AccessTestHelpers;

trait ExercisesWideNodeListing
{
    protected function assertWideNodeListing(): void
    {
        Storage::fake('local');
        config()->set('vaulter.drivers.permission', 'database');
        $actor = AccessTestHelpers::user();
        $this->actingAs($actor);

        $storage = AccessTestHelpers::storage();
        $root = $storage->rootNode()->firstOrFail();
        $anchor = AccessTestHelpers::folder($storage, $root, 'anchor');
        $outside = AccessTestHelpers::folder($storage, $root, 'outside');
        $foreignStorage = AccessTestHelpers::storage();
        $foreign = AccessTestHelpers::folder($foreignStorage, null, 'foreign');
        AccessTestHelpers::grant($root, GrantLevel::Reader, scope: GrantScope::Item);
        AccessTestHelpers::grant($anchor, GrantLevel::Reader, scope: GrantScope::Subtree);
        AccessTestHelpers::grant($foreign, GrantLevel::Reader, scope: GrantScope::Item);

        // Real descendants, not a mocked candidate list. Insert in bounded statements
        // so fixture setup itself stays below PostgreSQL's 65535-parameter limit.
        $count = 65_540;
        $connection = $anchor->getConnection();
        for ($start = 1; $start <= $count; $start += 500) {
            $rows = [];
            for ($i = $start; $i <= min($start + 499, $count); $i++) {
                $rows[] = [
                    'id' => $this->wideNodeKey($i),
                    'drive_id' => $storage->getKey(),
                    'parent_id' => $anchor->getKey(),
                    'kind' => NodeKind::Folder->value,
                    'name' => 'leaf-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                    'position' => 0,
                ];
            }
            $connection->table($anchor->getTable())->insert($rows);
        }

        $repository = app(NodeReadRepository::class);
        expect($repository->candidateNodeIds($actor))->toHaveCount($count + 3);
        $query = $repository->constrainToAccessible($repository->children($storage, $anchor), $actor);
        // Assert a statement-wide binding budget, including the storage/parent filters.
        expect($query->count())->toBe($count)
            ->and(count($query->getBindings()))->toBeLessThan(1_000);

        // The grant-reachable foreign node must still be excluded by the base query.
        $rootIds = $repository->constrainToAccessible($repository->children($storage, $root), $actor)->pluck('id')->all();
        expect($rootIds)->toBe([$anchor->getKey()])
            ->not->toContain($outside->getKey(), $foreign->getKey());

        // All siblings tie on position: retain ordering and both cursor directions.
        $page1 = (clone $query)->cursorPaginate(2);
        $page2 = (clone $query)->cursorPaginate(2, cursor: $page1->nextCursor());
        $back = (clone $query)->cursorPaginate(2, cursor: $page2->previousCursor());
        expect(collect($page1->items())->pluck('id')->all())->toBe([$this->wideNodeKey(1), $this->wideNodeKey(2)])
            ->and(collect($page2->items())->pluck('id')->all())->toBe([$this->wideNodeKey(3), $this->wideNodeKey(4)])
            ->and(collect($back->items())->pluck('id')->all())->toBe([$this->wideNodeKey(1), $this->wideNodeKey(2)]);

        $this->getJson('api/vaulter/storages/'.$storage->getKey().'/nodes?parent_id='.$anchor->getKey().'&per_page=2')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $this->wideNodeKey(1));

        // Candidate listing remains a superset; exact per-action deny still wins.
        $denied = Node::findOrFail($this->wideNodeKey(1));
        AccessTestHelpers::grant($denied, GrantLevel::Reader, effect: Effect::Deny, scope: GrantScope::Item);
        expect($repository->queryForUser($actor)->whereKey($denied->getKey())->exists())->toBeTrue();
        $this->getJson('api/vaulter/nodes/'.$denied->getKey())->assertForbidden();
        expect($repository->queryForUser(AccessTestHelpers::user(2))->count())->toBe(0);
    }

    private function wideNodeKey(int $offset): int|string
    {
        return Config::usesUlids()
            ? '01ARZ3NDEK'.str_pad((string) $offset, 16, '0', STR_PAD_LEFT)
            : 9_007_199_254_740_992 + $offset;
    }
}
