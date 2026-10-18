<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Sharing;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Sharing\NodeShare;

/** @internal */
#[Internal]
final readonly class UpdateShareLinkInput
{
    /** @param int|null $expectedVersion the link's version the caller read (If-Match); null skips the check */
    public function __construct(public Node $node, public NodeShare $share, public ShareLinkUpdate $changes, public ?int $expectedVersion = null) {}
}
