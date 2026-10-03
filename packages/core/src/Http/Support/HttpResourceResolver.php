<?php

declare(strict_types=1);

namespace Vaulter\Http\Support;

use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\StorageReadRepository;

final readonly class HttpResourceResolver
{
    public function __construct(
        private StorageReadRepository $storages,
        private NodeReadRepository $nodes,
    ) {}

    public function storage(int|string $id): Storage
    {
        return $this->storages->find($id) ?? throw StorageNotFoundException::forId($id);
    }

    public function node(int|string $id): Node
    {
        return $this->nodes->find($id) ?? throw NodeNotFoundException::forId($id);
    }
}
