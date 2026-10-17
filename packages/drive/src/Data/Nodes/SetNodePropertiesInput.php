<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Nodes;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Models\Node;

/** A patch of one namespace of a node's properties. */
#[Api]
final readonly class SetNodePropertiesInput
{
    /**
     * @param array<array-key, mixed> $set   key => string value to write
     * @param array<array-key, mixed> $unset keys to remove
     */
    #[Internal]
    public function __construct(
        public Node $node,
        public string $namespace,
        public array $set,
        public array $unset = [],
        public ?int $expectedSequence = null,
    ) {}
}
