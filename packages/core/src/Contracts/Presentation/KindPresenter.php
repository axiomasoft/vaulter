<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Presentation;

use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Handlers\NodePresentation;

/**
 * Presents every node of one kind instead of the file handler registry. A module that
 * owns a kind's content (documents) tags its implementation `vaulter.kind_presenters`;
 * core never imports the module.
 */
interface KindPresenter
{
    public function kind(): NodeKind;

    public function present(Node|NodeSnapshot $node): NodePresentation;
}
