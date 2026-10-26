<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/** Optional: the document type can be edited by several clients at once. */
#[Spi(SpiKind::Implement)]
interface SupportsCollaboration
{
    /** The persisted binary representation, independent of the wire version. */
    public function collaborationStateFormat(): string;

    /** e.g. 'yjs'. */
    public function collaborationProtocol(): string;

    /**
     * A JSON projection of the binary collaboration state (for export and search), or
     * null when the hub sends the JSON itself.
     */
    public function projectFromState(string $state): ?DocumentContent;
}
