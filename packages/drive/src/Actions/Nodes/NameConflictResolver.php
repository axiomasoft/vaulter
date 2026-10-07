<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Nodes;

use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Support\NodeName;

final readonly class NameConflictResolver
{
    public function __construct(private NodeReadRepository $nodes, private DriveConfig $config) {}

    /** Caller holds the drive tree and parent locks. */
    public function sibling(Drive $drive, Node $parent, string $name): ?Node
    {
        $name = NodeName::normalize($name, $this->config->nameMaxLength());

        return $this->nodes->findSibling($drive, $parent->id, $name);
    }

    public function resolve(Drive $drive, int|string $parentId, string $name, OnNameConflict $onConflict, int|string|null $ignoreId = null): string
    {
        $name = NodeName::normalize($name, $this->config->nameMaxLength());
        $candidate = $name;
        for ($i = 2; $i <= 7; $i++) {
            if (!$this->nodes->siblingNameExists(storage: $drive, parentId: $parentId, name: $candidate, ignoreId: $ignoreId)) {
                return $candidate;
            }
            if ($onConflict !== OnNameConflict::Rename || $i === 7) {
                throw NodeNameConflictException::forName($name, $parentId);
            }
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $stem = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
            $suffix = ' ('.$i.')'.($extension === '' ? '' : '.'.$extension);
            $candidate = mb_strcut($stem, 0, $this->config->nameMaxLength() - strlen($suffix), 'UTF-8').$suffix;
        }
        throw NodeNameConflictException::forName($name, $parentId);
    }
}
