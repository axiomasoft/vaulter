<?php

declare(strict_types=1);

namespace Vaulter\Tests\Content\Fixtures;

final class FirstDownloadGuard extends RecordingDownloadGuard
{
    protected function throws(): bool
    {
        return false;
    }
}
