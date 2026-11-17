<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Data\Page;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Http\Resources\Drive\NodeResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;
use Vaulter\Drive\Http\Support\ListQuery;
use Vaulter\Drive\Queries\SharedWithActorQuery;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\ValueObjects\Access\Actor;

/**
 * Nodes other people granted the caller, across drives. The listing needs grants, so
 * with that feature off it answers 403 profile_restriction (the route itself stays
 * registered). Abilities come from one batch per drive on the page, and a node whose
 * drive the caller may no longer reach (another tenant, being deleted) is left out.
 */
#[Internal]
final readonly class ListSharedWithMeController
{
    public function __construct(
        private HttpResourceResolver $resolver,
        private HttpOperationContextFactory $contexts,
        private SharedWithActorQuery $shared,
        private NodeSnapshotMapper $mapper,
        private DriveOptions $config,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->config->featureEnabled('grants')) {
            throw ProfileRestrictionException::forOperation('shared');
        }
        $options = ListQuery::options($request);
        $user = $this->contexts->user($request);
        $page = $this->mapper->page($this->shared->execute(actor: $user, options: $options), Actor::user($user)->ref());

        /** @var array<int|string, list<NodeSnapshot>> $byDrive */
        $byDrive = [];
        foreach ($page->items as $node) {
            $byDrive[$node->driveId][] = $node;
        }
        $abilities = [];
        foreach ($byDrive as $driveId => $nodes) {
            try {
                $handle = $this->contexts->forDrive($request, $this->resolver->drive((string) $driveId));
            } catch (StorageNotFoundException) {
                continue;
            }
            $abilities += $handle->nodes()->abilitiesMany($nodes);
        }

        return PageResponse::make(
            new Page(items: NodeResource::many($page->items, $abilities), nextCursor: $page->nextCursor),
            static fn (NodeResource $resource): NodeResource => $resource,
            $options->perPage,
        );
    }
}
