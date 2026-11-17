<?php

declare(strict_types=1);

namespace Vaulter\Drive\Queries;

use Packman\Attributes\Internal;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\NodePage;
use Vaulter\Drive\Data\SearchOptions;
use Vaulter\Drive\Enums\NodeSort;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\NodeReadRepository;

#[Internal]
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
