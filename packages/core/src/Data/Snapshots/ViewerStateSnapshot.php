<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots;

/** The acting user's personal state of a node. */
final readonly class ViewerStateSnapshot
{
    /** @internal */
    public function __construct(public bool $starred) {}
}
