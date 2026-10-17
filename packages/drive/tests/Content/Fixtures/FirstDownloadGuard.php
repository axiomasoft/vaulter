<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Content\Fixtures;

final class FirstDownloadGuard extends RecordingDownloadGuard
{
    protected function throws(): bool
    {
        return false;
    }
}
