<?php

declare(strict_types=1);

namespace Vaulter\Drive\Queries;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\ExactListing;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\NodePage;
use Vaulter\Drive\Enums\NodeSort;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\NodeUserState;
use Vaulter\Drive\Operations\DriveContext;

#[Internal]
final readonly class NodePageQuery
{
    public function __construct(private ExactListing $listing, private DriveConfig $config) {}

    /** @return Builder<Node> */
    public function candidates(DriveContext $context, bool $trash = false): Builder
    {
        $class = $this->config->model('node');
        $query = $class::query()->where('drive_id', $context->driveId());

        if ($trash) {
            // Only explicit trash roots are listed.
            $table = (new $class)->getTable();

            return $query->whereNotNull('trashed_at')->whereColumn($table.'.trash_root_id', $table.'.id');
        }

        return $query->whereNull('trashed_at');
    }

    /**
     * @param Builder<Node>         $query
     * @param list<int|string|null> $filter
     */
    public function page(DriveContext $context, Builder $query, ListOptions $options, string $kind, array $filter = []): NodePage
    {
        if ($options->kinds !== []) {
            $query->whereIn('kind', array_map(static fn ($kind): string => $kind->value, $options->kinds));
        }
        $columns = match ($options->sort) {
            NodeSort::Default => ['name', 'id'], NodeSort::Name => ['name', 'id'], NodeSort::UpdatedAt => ['updated_at', 'id'], NodeSort::Size => ['size_bytes', 'id'],
        };
        $direction = $options->descending ? 'desc' : 'asc';
        $order = array_map(static fn (string $column): array => ['column' => $column, 'direction' => $direction], $columns);
        $identity = json_encode(['drive' => (string) $context->driveId(), 'tenant' => $context->drive->tenant_id, 'kind' => $kind, 'filter' => $filter, 'kinds' => $options->kinds, 'sort' => $options->sort->value, 'direction' => $direction], JSON_THROW_ON_ERROR);

        return $this->listing->page(actor: $context, candidates: $query, order: $order, perPage: $options->perPage, cursor: $options->cursor, context: $identity);
    }

    /**
     * The acting user's starred nodes of this drive that are live and visible now,
     * newest star first. The star joins in under the node table's own name, so the
     * listing paginates over starred_at like over any node column.
     */
    public function starred(DriveContext $context, ListOptions $options): NodePage
    {
        if ($options->sort !== NodeSort::Default || $options->descending || $options->kinds !== []) {
            throw new InvalidArgumentException('Starred lists support perPage and cursor only.');
        }
        $actor = $context->actor->ref();
        if ($context->actor->isSystem() || $actor->type === null || $actor->id === null) {
            return new NodePage(items: [], nextCursor: null, scanned: 0);
        }

        $class = $this->config->model('node');
        $table = (new $class)->getTable();
        $states = (new NodeUserState)->getTable();
        $starred = $class::query()->toBase()
            ->join($states.' as vaulter_star', 'vaulter_star.node_id', '=', $table.'.id')
            ->where('vaulter_star.actor_type', $actor->type)
            ->where('vaulter_star.actor_id', $actor->id)
            ->whereNotNull('vaulter_star.starred_at')
            ->where($table.'.drive_id', $context->driveId())
            ->whereNull($table.'.trashed_at')
            ->select([$table.'.*', 'vaulter_star.starred_at as starred_at']);
        $order = [['column' => 'starred_at', 'direction' => 'desc'], ['column' => 'id', 'direction' => 'desc']];
        $identity = json_encode(['drive' => (string) $context->driveId(), 'tenant' => $context->drive->tenant_id, 'kind' => 'starred', 'actor' => [$actor->type, (string) $actor->id]], JSON_THROW_ON_ERROR);

        return $this->listing->page(actor: $context, candidates: $class::query()->fromSub($starred, $table), order: $order, perPage: $options->perPage, cursor: $options->cursor, context: $identity);
    }
}
