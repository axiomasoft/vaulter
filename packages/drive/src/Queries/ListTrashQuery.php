<?php

declare(strict_types=1);

namespace Vaulter\Drive\Queries;

use Packman\Attributes\Internal;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\NodePage;
use Vaulter\Drive\Operations\DriveContext;

#[Internal]
final readonly class ListTrashQuery
{
    public function __construct(private NodePageQuery $pages) {}

    public function execute(DriveContext $context, ListOptions $options): NodePage
    {
        return $this->pages->page($context, $this->pages->candidates($context, true), $options, 'trash');
    }
}
