<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

/** Optional: the document type can be edited by several clients at once. */
interface SupportsCollaboration
{
    /** e.g. 'yjs'. */
    public function collaborationProtocol(): string;

    /**
     * A JSON projection of the binary collaboration state (for export and search), or
     * null when the hub sends the JSON itself.
     */
    public function projectFromState(string $state): ?DocumentContent;
}
