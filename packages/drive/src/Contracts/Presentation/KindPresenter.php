<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Presentation;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Handlers\NodePresentation;

/**
 * Presents every node of one kind instead of the file handler registry (slot `drive`, Handler keyed by the
 * kind). A module that owns a kind's content (documents) contributes its implementation under the kind's
 * value; core never imports the module.
 */
#[Spi(SpiKind::Implement)]
interface KindPresenter
{
    public function kind(): NodeKind;

    public function present(Node|NodeSnapshot $node): NodePresentation;
}
