<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Nodes;

use Vaulter\Models\Drive\Node;

/** A patch of one namespace of a node's properties (PLAN2.D41). */
final readonly class SetNodePropertiesInput
{
    /**
     * @param array<array-key, mixed> $set   key => string value to write
     * @param array<array-key, mixed> $unset keys to remove
     */
    public function __construct(
        public Node $node,
        public string $namespace,
        public array $set,
        public array $unset = [],
        public ?int $expectedSequence = null,
    ) {}
}
