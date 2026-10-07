<?php

declare(strict_types=1);

namespace Vaulter\Drive\Queries;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Access\ExactListing;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\NodePage;
use Vaulter\Enums\Drive\NodeSort;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;

final readonly class NodePageQuery
{
    public function __construct(private ExactListing $listing, private VaulterConfig $config) {}

    /** @return Builder<Node> */
    public function candidates(OperationContext $context, bool $trash = false): Builder
    {
        $class = $this->config->model('node');
        $query = $class::query()->where('drive_id', $context->driveId());

        return $trash ? $query->whereNotNull('trashed_at') : $query->whereNull('trashed_at');
    }

    /**
     * @param Builder<Node>         $query
     * @param list<int|string|null> $filter
     */
    public function page(OperationContext $context, Builder $query, ListOptions $options, string $kind, array $filter = []): NodePage
    {
        if ($options->kinds !== []) {
            $query->whereIn('kind', array_map(static fn ($kind): string => $kind->value, $options->kinds));
        }
        $columns = match ($options->sort) {
            NodeSort::Default => ['position', 'name', 'id'], NodeSort::Name => ['name', 'id'], NodeSort::UpdatedAt => ['updated_at', 'id'], NodeSort::Size => ['size_bytes', 'id'],
        };
        $direction = $options->descending ? 'desc' : 'asc';
        $order = array_map(static fn (string $column): array => ['column' => $column, 'direction' => $direction], $columns);
        $identity = json_encode(['drive' => (string) $context->driveId(), 'tenant' => $context->drive->tenant_id, 'kind' => $kind, 'filter' => $filter, 'kinds' => $options->kinds, 'sort' => $options->sort->value, 'direction' => $direction], JSON_THROW_ON_ERROR);

        return $this->listing->page(actor: $context, candidates: $query, order: $order, perPage: $options->perPage, cursor: $options->cursor, context: $identity);
    }
}
