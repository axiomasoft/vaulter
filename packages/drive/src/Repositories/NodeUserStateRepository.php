<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Packman\Attributes\Internal;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\NodeUserState;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * Reads and writes one actor's personal node state. Personal state
 * takes no tree locks and never touches the node row: it changes neither the
 * node's sequence nor its timestamps.
 */
#[Internal]
final readonly class NodeUserStateRepository
{
    /** Idempotent: starring a starred node keeps its original starred_at. */
    public function star(Node $node, ActorRef $actor): void
    {
        $state = $this->query($node, $actor)->first();
        if ($state instanceof NodeUserState) {
            if ($state->starred_at === null) {
                $state->forceFill(['starred_at' => $state->freshTimestamp()])->save();
            }

            return;
        }

        $state = new NodeUserState;
        try {
            $state->forceFill([
                'node_id' => $node->getKey(),
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
                'starred_at' => $state->freshTimestamp(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent star of the same node by the same actor won; the node is starred.
        }
    }

    public function unstar(Node $node, ActorRef $actor): void
    {
        $this->query($node, $actor)->whereNotNull('starred_at')->update(['starred_at' => null]);
    }

    /**
     * The ids among $nodeIds the actor has starred, keyed by stringified id.
     *
     * @param  list<int|string>    $nodeIds
     * @return array<string, true>
     */
    public function starredAmong(string $actorType, int|string $actorId, array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $starred = [];
        foreach (array_chunk($nodeIds, 500) as $chunk) {
            foreach (NodeUserState::query()->where('actor_type', $actorType)->where('actor_id', $actorId)
                ->whereIn('node_id', $chunk)->whereNotNull('starred_at')->pluck('node_id') as $id) {
                if (is_int($id) || is_string($id)) {
                    $starred[Identity::stringify($id)] = true;
                }
            }
        }

        return $starred;
    }

    /** @return Builder<NodeUserState> */
    private function query(Node $node, ActorRef $actor): Builder
    {
        return NodeUserState::query()
            ->where('node_id', $node->getKey())
            ->where('actor_type', $actor->type)
            ->where('actor_id', $actor->id);
    }
}
