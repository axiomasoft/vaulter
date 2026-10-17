<?php

declare(strict_types=1);

namespace Vaulter\Drive\Queries;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\NodePage;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;

#[Internal]
final readonly class ListChildrenQuery
{
    public function __construct(private NodePageQuery $pages) {}

    public function execute(DriveContext $context, Node $folder, ListOptions $options): NodePage
    {
        return $this->pages->page($context, $this->pages->candidates($context)->where('parent_id', $folder->id), $options, 'children', [(string) $folder->id]);
    }
}
