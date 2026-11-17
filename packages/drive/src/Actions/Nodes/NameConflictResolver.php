<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Nodes;

use Packman\Attributes\Internal;
use Vaulter\Drive\Enums\OnNameConflict;
use Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Naming\NamePolicy;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Support\NodeName;

#[Internal]
final readonly class NameConflictResolver
{
    public function __construct(private NodeReadRepository $nodes) {}

    /** Caller holds the drive tree and parent locks. */
    public function sibling(Drive $drive, Node $parent, string $name): ?Node
    {
        return $this->nodes->findSibling($drive, $parent->id, NodeName::normalize($name));
    }

    /**
     * The name itself when no live sibling shares its key, otherwise (on Rename) the
     * first free "<stem> (N)<.ext>". A generated name is a new name: with a policy
     * given it must pass it, so a stored name the policy refuses is never copied
     * into a fresh variant.
     */
    public function resolve(Drive $drive, int|string $parentId, string $name, OnNameConflict $onConflict, int|string|null $ignoreId = null, ?NamePolicy $policy = null): string
    {
        $name = NodeName::normalize($name);
        $candidate = $name;
        for ($i = 2; $i <= 7; $i++) {
            if (!$this->nodes->siblingNameExists(storage: $drive, parentId: $parentId, name: $candidate, ignoreId: $ignoreId)) {
                return $candidate === $name || $policy === null ? $candidate : $policy->apply($candidate);
            }
            if ($onConflict !== OnNameConflict::Rename || $i === 7) {
                throw NodeNameConflictException::forName($name, $parentId);
            }
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $stem = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
            $suffix = ' ('.$i.')'.($extension === '' ? '' : '.'.$extension);
            $candidate = mb_strcut($stem, 0, NodeName::MAX_BYTES - strlen($suffix), 'UTF-8').$suffix;
        }
        throw NodeNameConflictException::forName($name, $parentId);
    }
}
