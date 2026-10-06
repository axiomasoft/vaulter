<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Folders;

use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Data\Nodes\CreateFolderInput;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\DriveNameRules;

/**
 * Validates a create-folder request (§2.2) and builds its CreateDriveFolderData
 * command. The destination parent and target storage are route/repository
 * resolved by the controller (the swappable Node/Storage models bind there), so
 * toData() receives them already-resolved — the Request owns the name validation
 * and the command shape.
 */
final class CreateFolderRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(DriveConfig $driveConfig): array
    {
        return [
            'name' => DriveNameRules::rules(maxLength: $driveConfig->nameMaxLength()),
            'parent_id' => ['nullable'],
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

    public function parentId(): int|string|null
    {
        return $this->nullableScalarId('parent_id');
    }

    /**
     * Assemble the command once the controller has resolved the storage and the
     * destination parent node (null = storage root).
     */
    public function toData(Drive $storage, ?Node $parent, int|string|null $actorId): CreateFolderInput
    {
        return new CreateFolderInput(
            storage: $storage,
            name: $this->name(),
            parent: $parent,
            actorId: $actorId,
        );
    }
}
