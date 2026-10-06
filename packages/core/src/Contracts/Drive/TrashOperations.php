<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\NodePage;
use Vaulter\Data\Drive\RestoreOptions;
use Vaulter\Models\Drive\Node;

interface TrashOperations
{
    public function list(?ListOptions $options = null): NodePage;

    public function restore(Node|int|string $node, ?RestoreOptions $options = null): Node;

    public function purge(Node|int|string $node): void;

    public function empty(): int;
}
