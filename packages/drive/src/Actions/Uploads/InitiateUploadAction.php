<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Uploads;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\NotAFileException;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\NodeLocator;
use Vaulter\Profiles\DrivePolicyEnforcer;
use Vaulter\Support\NodeName;

#[Internal]
final readonly class InitiateUploadAction
{
    public function __construct(private NodeLocator $locator, private OperationGuard $guard, private UploadSessionManager $manager, private DrivePolicyEnforcer $policy) {}

    public function execute(DriveContext $context, InitiateUploadInput $input): UploadSession
    {
        $node = $input->targetNode === null ? null : $this->locator->find(context: $context, key: $input->targetNode);
        $parent = $node === null ? $this->locator->find(context: $context, key: $input->targetParent ?? $context->drive->root_node_id ?? throw NodeNotFoundException::forId('root')) : null;
        if ($node !== null) {
            $this->guard->authorize(context: $context, ability: Ability::Edit, node: $node);
            if ($node->kind !== NodeKind::File) {
                throw new NotAFileException;
            }
        } else {
            $this->guard->authorizeCreate(context: $context, parent: $parent ?? throw NodeNotFoundException::forId('root'));
        }

        // Refuse what the drive policy will refuse at completion before any byte is sent.
        if ($input->totalSizeBytes !== null) {
            $this->policy->assertFileSize(drive: $context->drive, bytes: $input->totalSizeBytes);
        }
        // A revision's name is only its original_name; a new file's name is a new node name.
        $name = $node !== null
            ? NodeName::normalize($input->targetName ?? $node->name)
            : $this->policy->name(drive: $context->drive, name: $input->targetName ?? 'upload.bin');

        return $this->manager->initiate(context: $context, data: new InitiateUploadInput(
            protocol: $input->protocol, targetParent: $parent, targetName: $name,
            targetNode: $node, expectedSha256: $input->expectedSha256, totalSizeBytes: $input->totalSizeBytes,
            mimeType: $input->mimeType, metadata: $input->metadata, expectedSequence: $input->expectedSequence,
        ));
    }
}
