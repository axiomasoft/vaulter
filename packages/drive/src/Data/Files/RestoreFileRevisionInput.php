<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Files;

use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;

final readonly class RestoreFileRevisionInput
{
    public function __construct(public Node $node, public FileRevision $revision, public ?int $expectedSequence = null) {}
}
