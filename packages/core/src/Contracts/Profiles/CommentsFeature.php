<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Profiles;

/** @internal Whether comments are globally on; the package that owns comments answers. */
interface CommentsFeature
{
    public function enabled(): bool;
}
