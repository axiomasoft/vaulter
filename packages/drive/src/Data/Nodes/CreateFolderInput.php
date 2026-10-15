<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Nodes;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;

#[Api]
final readonly class CreateFolderInput
{
    #[Internal]
    public function __construct(public Drive $storage, public string $name, public ?Node $parent = null, public OnNameConflict $onConflict = OnNameConflict::Fail) {}
}
