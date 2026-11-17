<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Changes;

use Packman\Attributes\Api;

/**
 * A page of a drive's change journal. Apply the changes in order (a node may appear
 * more than once: the last one wins), then store $cursor; with $hasMore, ask for the
 * next page at once.
 */
#[Api]
final readonly class ChangePage
{
    /** @param list<NodeChange> $changes */
    public function __construct(
        public array $changes,
        public string $cursor,
        public bool $hasMore,
    ) {}
}
