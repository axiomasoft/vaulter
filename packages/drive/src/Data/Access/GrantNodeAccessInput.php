<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Access;

use Packman\Attributes\Internal;
use Vaulter\Drive\Models\Node;

/** @internal Located command; the public grant value never carries caller authority. */
#[Internal]
final readonly class GrantNodeAccessInput
{
    public function __construct(public Node $node, public GrantInput $grant) {}
}
