<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Nodes;

use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;

final readonly class CreateFolderInput
{
    public function __construct(public Drive $storage, public string $name, public ?Node $parent = null, public OnNameConflict $onConflict = OnNameConflict::Fail) {}
}
