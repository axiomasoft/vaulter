<?php

declare(strict_types=1);

namespace Vaulter\Drive\Queries;

use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\NodePage;
use Vaulter\Operations\DriveContext;

final readonly class ListTrashQuery
{
    public function __construct(private NodePageQuery $pages) {}

    public function execute(DriveContext $context, ListOptions $options): NodePage
    {
        return $this->pages->page($context, $this->pages->candidates($context, true), $options, 'trash');
    }
}
