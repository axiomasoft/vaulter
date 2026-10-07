<?php

declare(strict_types=1);

namespace Vaulter\Profiles;

use Vaulter\Contracts\Profiles\CommentsFeature;

/** @internal Default when no installed package owns comments (vaulter-documents rebinds it). */
final readonly class DisabledCommentsFeature implements CommentsFeature
{
    public function enabled(): bool
    {
        return false;
    }
}
