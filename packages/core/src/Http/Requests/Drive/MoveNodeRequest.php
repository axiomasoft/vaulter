<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Drive;

use Vaulter\Data\MoveNodeData;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Models\Node;

/**
 * Validates a move request (§2.2): the node being moved is route-model resolved,
 * the destination parent comes from the body (null = storage root, resolved by
 * the controller). toData() takes both already-resolved nodes.
 */
final class MoveNodeRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['nullable'],
        ];
    }

    public function parentId(): int|string|null
    {
        return $this->nullableScalarId('parent_id');
    }

    public function toData(Node $node, ?Node $into, int|string|null $actorId): MoveNodeData
    {
        return new MoveNodeData(
            node: $node,
            into: $into,
            actorId: $actorId,
        );
    }
}
