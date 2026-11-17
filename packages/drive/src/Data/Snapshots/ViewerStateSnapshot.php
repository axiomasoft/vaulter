<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots;

use Packman\Attributes\Api;
use Packman\Attributes\Internal;

/** The acting user's personal state of a node. */
#[Api]
final readonly class ViewerStateSnapshot
{
    #[Internal]
    public function __construct(public bool $starred) {}
}
