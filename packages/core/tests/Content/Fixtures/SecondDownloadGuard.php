<?php

declare(strict_types=1);

namespace Vaulter\Tests\Content\Fixtures;

final class SecondDownloadGuard extends RecordingDownloadGuard
{
    protected function throws(): bool
    {
        return true;
    }
}
