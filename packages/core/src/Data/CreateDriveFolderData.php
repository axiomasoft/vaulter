<?php

declare(strict_types=1);

namespace Vaulter\Data;

use Vaulter\Models\Node;
use Vaulter\Models\Storage;

/**
 * Command for CreateFolderAction (§2.2): create a kind=folder node under a parent
 * (or the storage root when $parent is null). The legacy CreateFolderData drives
 * the DocDrive v_document_folders tree; this one drives the unified v_nodes Drive
 * tree, so it is deliberately a distinct, node-typed command.
 */
final readonly class CreateDriveFolderData
{
    public function __construct(
        public Storage $storage,
        public string $name,
        public ?Node $parent = null,
        public int|string|null $actorId = null,
    ) {}
}
