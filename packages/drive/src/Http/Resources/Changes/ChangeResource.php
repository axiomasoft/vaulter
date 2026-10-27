<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources\Changes;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Data\Changes\NodeChange;
use Vaulter\Drive\Enums\Changes\ChangeOperation;
use Vaulter\Drive\Http\Resources\Drive\NodeResource;

/**
 * One entry of a drive's change journal. An upsert carries the node as the reader sees it
 * now; a remove is minimal, `operation` and the reader-scoped `sync_key` only, and the
 * client drops the node it matches by key (and everything below it) from its copy.
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
        if ($change->operation === ChangeOperation::Remove) {
            return ['operation' => $change->operation->value, 'sync_key' => $change->syncKey];
        }
        $node = $change->node !== null && $this->abilities !== null ? new NodeResource($change->node, $this->abilities) : null;

        return [
            'operation' => $change->operation->value,
            'sync_key' => $change->syncKey,
            'node_id' => $change->nodeId,
            'subtree' => $change->subtree,
            'node_sequence' => $change->nodeSequence,
            'node' => $node?->toArray($request),
        ];
    }
}
