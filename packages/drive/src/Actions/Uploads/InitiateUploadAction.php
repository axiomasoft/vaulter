<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Uploads;

use Packman\Attributes\Internal;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\NotAFileException;
use Vaulter\Drive\Exceptions\Nodes\StaleNodeException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Profiles\DrivePolicyEnforcer;
use Vaulter\Drive\Support\NodeName;
use Vaulter\Drive\Uploads\UploadSessionManager;

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
            // A stale expectation is refused before any byte is sent (a keyed HTTP retry whose If-Match went stale
            // arrives here with a sequence no live file holds); a matching one is kept for the commit.
            if ($input->expectedSequence !== null && $node->sequence !== $input->expectedSequence) {
                throw StaleNodeException::forNode($node->id);
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

        // The located rows were authorized above; the session records their keys.
        return $this->manager->initiate(context: $context, data: new InitiateUploadInput(
            protocol: $input->protocol, targetParent: $parent === null ? null : self::key($parent), targetName: $name,
            targetNode: $node === null ? null : self::key($node), expectedSha256: $input->expectedSha256, totalSizeBytes: $input->totalSizeBytes,
            mimeType: $input->mimeType, metadata: $input->metadata, expectedSequence: $input->expectedSequence,
        ));
    }

    private static function key(Node $node): int|string
    {
        $key = $node->getRawOriginal($node->getKeyName());

        return is_int($key) || is_string($key) ? $key : throw NodeNotFoundException::forId('node');
    }
}
