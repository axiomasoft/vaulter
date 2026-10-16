<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Files;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;

#[Api]
final readonly class RestoreFileRevisionInput
{
    #[Internal]
    public function __construct(public Node $node, public FileRevision $revision, public ?int $expectedSequence = null) {}
}
