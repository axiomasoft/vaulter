<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Uploads;

use Vaulter\Access\OperationGuard;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\NotAFileException;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Support\NodeName;

final readonly class InitiateUploadAction
{
    public function __construct(private NodeLocator $locator, private OperationGuard $guard, private UploadSessionManager $manager) {}

    public function execute(OperationContext $context, InitiateUploadInput $input): UploadSession
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

        return $this->manager->initiate(context: $context, data: new InitiateUploadInput(
            protocol: $input->protocol, targetParent: $parent, targetName: NodeName::normalize($input->targetName ?? $node->name ?? 'upload.bin'),
            targetNode: $node, expectedSha256: $input->expectedSha256, totalSizeBytes: $input->totalSizeBytes,
            mimeType: $input->mimeType, metadata: $input->metadata, expectedSequence: $input->expectedSequence,
        ));
    }
}
