<?php

declare(strict_types=1);

namespace Vaulter\Drive\Queries;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\OperationGuard;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;

/** @internal Read adapters for the existing browser and panel representations. */
final readonly class NodeBrowserQuery
{
    public function __construct(
        private NodeReadRepository $nodes,
        private DriveReadRepository $drives,
        private NodeLocator $locator,
        private OperationGuard $guard,
        private AccessResolver $access,
        private VaulterConfig $config,
    ) {}

    public function driveForNode(Node $node): Drive
    {
        $driveId = $node->getRawOriginal('drive_id');
        if (!is_int($driveId) && !is_string($driveId)) {
            throw NodeNotFoundException::forId('unpersisted');
        }

        return $this->drives->find($driveId) ?? throw NodeNotFoundException::forId($node->id);
    }

    /** @return Collection<int, Node> */
    public function breadcrumb(DriveHandle $handle, Node $node): Collection
    {
        $node = $handle->nodes()->find($node);

        return new Collection($this->guard->visible(context: $this->context($handle), nodes: $this->nodes->breadcrumb($node)));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel> $query
     * @return Builder<TModel>
     */
    public function constrainToAccessible(Builder $query, Authorizable $actor): Builder
    {
        return $this->nodes->constrainToAccessible($query, $actor);
    }

    /** @return array<int|string, string> */
    public function folderOptions(DriveHandle $handle, Node $source): array
    {
        $context = $this->context($handle);
        $actor = $context->actor->authorizable();
        if ($actor === null || !$this->access->canCreateIn($actor, $context->drive)) {
            return [];
        }
        $model = $this->config->model('node');
        $folders = $model::query()->where('drive_id', $context->driveId())
            ->where('kind', NodeKind::Folder->value)->whereNull('trashed_at')->whereKeyNot($source->id)
            ->orderBy('name')->get();
        $options = [];
        foreach ($this->guard->visible(context: $context, nodes: $folders) as $folder) {
            try {
                $this->guard->authorizeCreate(context: $context, parent: $folder);
                $options[$folder->id] = $folder->name === '' ? '(root)' : $folder->name;
            } catch (PermissionDeniedException|NodeNotFoundException|ProfileRestrictionException) {
                continue;
            }
        }

        return $options;
    }

    public function can(DriveHandle $handle, Ability $ability, Node $node): bool
    {
        $context = $this->context($handle);
        try {
            $node = $this->locator->find(context: $context, key: $node, includeTrashed: true);
            $operation = match ($ability) {
                Ability::View => OperationKind::Metadata,
                Ability::Download => OperationKind::ContentEgress,
                Ability::Delete => OperationKind::Purge,
                default => OperationKind::Mutation,
            };
            $this->guard->authorize(context: $context, ability: $ability, node: $node, operation: $operation);

            return true;
        } catch (PermissionDeniedException|NodeNotFoundException|ProfileRestrictionException) {
            return false;
        }
    }

    /** @return array<int|string, string> */
    public function driveOptions(): array
    {
        $model = $this->config->model('drive');

        /** @var array<int|string, string> $options */
        $options = $model::query()->orderBy('name')->pluck('name', $model::query()->getModel()->getKeyName())->all();

        return $options;
    }

    private function context(DriveHandle $handle): OperationContext
    {
        return new OperationContext(drive: $handle->model(), actor: $handle->actor(), correlationId: (string) Str::ulid());
    }
}
