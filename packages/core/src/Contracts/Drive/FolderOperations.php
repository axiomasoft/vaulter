<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Attributes\Api;
use Vaulter\Data\Drive\CreateFolderOptions;
use Vaulter\Data\Snapshots\NodeSnapshot;

#[Api]
interface FolderOperations
{
    public function create(string $name, NodeSnapshot|string|int|null $parent = null, ?CreateFolderOptions $options = null): NodeSnapshot;
}
