<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Nodes;

use Packman\Attributes\Internal;
use Vaulter\Drive\Enums\OnNameConflict;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;

#[Internal]
final readonly class CreateFolderInput
{
    #[Internal]
    public function __construct(public Drive $storage, public string $name, public ?Node $parent = null, public OnNameConflict $onConflict = OnNameConflict::Fail) {}
}
