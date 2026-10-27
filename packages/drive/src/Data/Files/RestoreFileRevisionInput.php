<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Files;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;

#[Internal]
final readonly class RestoreFileRevisionInput
{
    #[Internal]
    public function __construct(public Node $node, public FileRevision $revision, public ?int $expectedSequence = null) {}
}
