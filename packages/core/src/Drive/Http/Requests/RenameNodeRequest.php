<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests;

use Vaulter\Drive\Data\RenameNodeData;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Models\Drive\Node;

/**
 * Validates a rename request (§2.2) and builds its RenameNodeData command. The
 * node being renamed is route-model resolved by the controller; toData() takes it
 * already-resolved.
 */
final class RenameNodeRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => DriveNameRules::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return DriveNameRules::messages();
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }

    public function toData(Node $node, int|string|null $actorId): RenameNodeData
    {
        return new RenameNodeData(
            node: $node,
            name: $this->name(),
            actorId: $actorId,
        );
    }
}
