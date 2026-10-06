<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Data\Drive\CreateFolderOptions;
use Vaulter\Models\Drive\Node;

interface FolderOperations
{
    public function create(string $name, Node|int|string|null $parent = null, ?CreateFolderOptions $options = null): Node;
}
