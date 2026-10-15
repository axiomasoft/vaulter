<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Changes;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Access\ValueObjects\NodeAbilities;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Changes\NodeChange;
use Vaulter\Http\Resources\Drive\NodeResource;

/**
 * One entry of a drive's change journal. `node` is the node as the reader sees it now,
 * or null when it is gone or no longer visible to the reader; a client then drops it
 * (and, with `subtree`, everything below it) from its copy.
 *
 * @mixin NodeChange
 */
#[Internal]
final class ChangeResource extends JsonResource
{
    public function __construct(NodeChange $change, private readonly ?NodeAbilities $abilities)
    {
        parent::__construct($change);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var NodeChange $change */
        $change = $this->resource;
        $node = $change->node !== null && $this->abilities !== null ? new NodeResource($change->node, $this->abilities) : null;

        return [
            'node_id' => $change->nodeId,
            'operation' => $change->operation->value,
            'reason' => $change->reason?->value,
            'subtree' => $change->subtree,
            'node_sequence' => $change->nodeSequence,
            'node' => $node?->toArray($request),
        ];
    }
}
