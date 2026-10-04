<?php

declare(strict_types=1);

namespace Vaulter\Http\Support;

use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;

final readonly class HttpResourceResolver
{
    public function __construct(
        private DriveReadRepository $storages,
        private NodeReadRepository $nodes,
    ) {}

    public function storage(int|string $id): Drive
    {
        return $this->storages->find($id) ?? throw StorageNotFoundException::forId($id);
    }

    public function node(int|string $id): Node
    {
        return $this->nodes->find($id) ?? throw NodeNotFoundException::forId($id);
    }
}
