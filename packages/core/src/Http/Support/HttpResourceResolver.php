<?php

declare(strict_types=1);

namespace Vaulter\Http\Support;

use Vaulter\Configuration\TenancyConfig;
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
        $drive = $this->storages->find($id);
        if ($drive === null) {
            if (app(TenancyConfig::class)->enabled()) {
                throw NodeNotFoundException::forId('drive');
            }
            throw StorageNotFoundException::forId($id);
        }

        return $drive;
    }

    public function node(int|string $id): Node
    {
        return $this->nodes->find($id) ?? throw NodeNotFoundException::forId($id);
    }
}
