<?php

declare(strict_types=1);

namespace Vaulter\Data\Access;

use Vaulter\Models\Drive\Node;

/** @internal Located command; the public grant value never carries caller authority. */
final readonly class GrantNodeAccessInput
{
    public function __construct(public Node $node, public GrantInput $grant) {}
}
