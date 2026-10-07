<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Nodes;

use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Data\Nodes\RenameNodeInput;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\DriveNameRules;

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
    public function rules(DriveConfig $driveConfig): array
    {
        return [
            'name' => DriveNameRules::rules(maxLength: $driveConfig->nameMaxLength()),
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

    public function toData(Node $node): RenameNodeInput
    {
        return new RenameNodeInput(
            node: $node,
            name: $this->name(),
        );
    }
}
