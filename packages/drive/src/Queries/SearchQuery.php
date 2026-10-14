<?php

declare(strict_types=1);

namespace Vaulter\Drive\Queries;

use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\NodePage;
use Vaulter\Data\Drive\SearchOptions;
use Vaulter\Enums\Drive\NodeSort;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Repositories\Drive\NodeReadRepository;

final readonly class SearchQuery
{
    public function __construct(private NodePageQuery $pages, private NodeReadRepository $nodes) {}

    public function execute(DriveContext $context, string $term, SearchOptions $options, ?Node $within = null): NodePage
    {
        $query = $this->nodes->search(storage: $context->drive, term: $term)->whereNull('trashed_at');
        if ($within !== null) {
            $query->whereKey($this->nodes->subtree($within)->modelKeys());
        }

        return $this->pages->page($context, $query, new ListOptions(perPage: $options->perPage, cursor: $options->cursor, sort: NodeSort::Name), 'search', [$term, $within?->id]);
    }
}
