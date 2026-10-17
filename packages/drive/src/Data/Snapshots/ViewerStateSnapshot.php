<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;

/** The acting user's personal state of a node. */
#[Api]
final readonly class ViewerStateSnapshot
{
    #[Internal]
    public function __construct(public bool $starred) {}
}
