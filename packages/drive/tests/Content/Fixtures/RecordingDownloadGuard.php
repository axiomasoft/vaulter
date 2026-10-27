<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Content\Fixtures;

use Throwable;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Drive\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Operations\DriveContext;

/** Records every check; the second guard throws what a test sets. */
abstract class RecordingDownloadGuard implements DownloadGuard
{
    /** @var list<string> */
    public static array $calls = [];

    public static ?Throwable $failure = null;

    /** @var list<array{0: class-string, 1: string, 2: string}> the context class and the node and revision ids each check saw */
    public static array $inputs = [];

    public function check(DriveContext|ShareLinkSnapshot $context, NodeSnapshot $node, FileRevisionSnapshot $revision): void
    {
        self::$calls[] = static::class;
        self::$inputs[] = [$context::class, $node->id, $revision->id];
        if ($this->throws() && self::$failure !== null) {
            throw self::$failure;
        }
    }

    abstract protected function throws(): bool;
}
