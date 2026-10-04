<?php

declare(strict_types=1);

namespace Vaulter\Access\ValueObjects;

use Vaulter\Models\Drive\Drive;

/**
 * The workspace/panel context an access decision is evaluated within (master plan
 * H.2). It carries the panel, the host workspace coordinates (so a PermissionDriver
 * can resolve a workspace-scoped role without ever seeing a Node), and the node
 * owner — never a Node or a magic string, keeping the PermissionDriver contract
 * identity-model-agnostic and node-blind.
 *
 * Vaulter derives this from a Storage (its owner_type/owner_id ARE the workspace)
 * and the node's owner before handing it to the driver.
 */
final readonly class AccessScope
{
    public function __construct(
        public string $panel,
        public ?string $workspaceType = null,
        public int|string|null $workspaceId = null,
        public ?Subject $nodeOwner = null,
    ) {}

    /**
     * Derive the scope from the storage a node lives in (the workspace = the
     * storage owner morph, §2.1) and the node's optional owner subject.
     */
    public static function forStorage(Drive $storage, ?Subject $nodeOwner = null): self
    {
        return new self(
            panel: $storage->panel,
            workspaceType: $storage->owner_type,
            workspaceId: $storage->owner_id,
            nodeOwner: $nodeOwner,
        );
    }
}
