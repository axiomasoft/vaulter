<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Concerns;

use Illuminate\Support\Facades\Storage;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Testing\Support\Scale;

#[Internal]
trait ExercisesWideNodeListing
{
    protected function assertWideNodeListing(): void
    {
        Storage::fake('local');
        config()->set('vaulter.access.permission_driver', 'database');
        $actor = AccessTestHelpers::user();
        $this->actingAs($actor);

        $storage = AccessTestHelpers::storage();
        $root = $storage->rootNode()->firstOrFail();
        $anchor = AccessTestHelpers::folder($storage, $root, 'anchor');
        $outside = AccessTestHelpers::folder($storage, $root, 'outside');
        $foreignStorage = AccessTestHelpers::storage();
        $foreign = AccessTestHelpers::folder($foreignStorage, null, 'foreign');
        AccessTestHelpers::grant($root, RoleRegistry::READER, scope: GrantScope::Item);
        AccessTestHelpers::grant($anchor, RoleRegistry::READER, scope: GrantScope::Subtree);
        AccessTestHelpers::grant($foreign, RoleRegistry::READER, scope: GrantScope::Item);

        // Real descendants, not a mocked candidate list. Insert in bounded statements
        // so fixture setup itself stays below PostgreSQL's 65535-parameter limit.
        // Full run: above PostgreSQL's 65535 bind limit. Fast lane: above the 1_000 binding budget asserted below, same code path.
        $count = Scale::pick(65_540, 2_500);
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
                    'name_key' => 'leaf-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
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

        // Siblings ordered by name: retain ordering and both cursor directions.
        $page1 = (clone $query)->cursorPaginate(2);
        $page2 = (clone $query)->cursorPaginate(2, cursor: $page1->nextCursor());
        $back = (clone $query)->cursorPaginate(2, cursor: $page2->previousCursor());
        expect(collect($page1->items())->pluck('id')->all())->toBe([$this->wideNodeKey(1), $this->wideNodeKey(2)])
            ->and(collect($page2->items())->pluck('id')->all())->toBe([$this->wideNodeKey(3), $this->wideNodeKey(4)])
            ->and(collect($back->items())->pluck('id')->all())->toBe([$this->wideNodeKey(1), $this->wideNodeKey(2)]);

        $this->getJson('api/vaulter/v1/drives/'.$storage->getKey().'/nodes?parent='.$anchor->getKey().'&per_page=2')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', (string) $this->wideNodeKey(1));

        // Candidate listing remains a superset; the exact per-action gate honours an
        // inheritance boundary the SQL does not apply.
        $denied = Node::findOrFail($this->wideNodeKey(1));
        app(VaulterDatabase::class)->write(fn () => $denied->forceFill(['inherit_grants' => false])->save());
        expect($repository->queryForUser($actor)->whereKey($denied->getKey())->exists())->toBeTrue();
        $this->getJson('api/vaulter/v1/nodes/'.$denied->getKey())->assertNotFound();
        expect($repository->queryForUser(AccessTestHelpers::user(2))->count())->toBe(0);
    }

    private function wideNodeKey(int $offset): int|string
    {
        return app(IdsConfig::class)->usesUlids()
            ? '01ARZ3NDEK'.str_pad((string) $offset, 16, '0', STR_PAD_LEFT)
            : 9_007_199_254_740_992 + $offset;
    }
}
