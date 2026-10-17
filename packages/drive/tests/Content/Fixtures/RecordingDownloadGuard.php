<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Content\Fixtures;

use Throwable;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Sharing\ShareContext;

/** Records every check; the second guard throws what a test sets. */
abstract class RecordingDownloadGuard implements DownloadGuard
{
    /** @var list<string> */
    public static array $calls = [];

    public static ?Throwable $failure = null;

    public function check(DriveContext|ShareContext $context, Node $node, FileRevision $revision): void
    {
        self::$calls[] = static::class;
        if ($this->throws() && self::$failure !== null) {
            throw self::$failure;
        }
    }

    abstract protected function throws(): bool;
}
