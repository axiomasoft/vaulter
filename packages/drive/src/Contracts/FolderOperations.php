<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts;

use Vaulter\Attributes\Api;
use Vaulter\Drive\Data\CreateFolderOptions;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;

#[Api]
interface FolderOperations
{
    public function create(string $name, NodeSnapshot|string|int|null $parent = null, ?CreateFolderOptions $options = null): NodeSnapshot;
}
