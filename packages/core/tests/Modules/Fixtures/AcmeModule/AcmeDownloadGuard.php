<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

use RuntimeException;
use Vaulter\Contracts\Drive\DownloadGuard;
use Vaulter\Exceptions\Files\ContentUnavailableException;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Sharing\ShareContext;

/** Vetoes a delivery by throwing: allow | deny | explode (an exception that is not a problem). */
final class AcmeDownloadGuard implements DownloadGuard
{
    public static string $mode = 'allow';

    public static int $checks = 0;

    public function __construct()
    {
        AcmeProbe::resolved(self::class);
    }

    public function check(DriveContext|ShareContext $context, Node $node, FileRevision $revision): void
    {
        self::$checks++;

        match (self::$mode) {
            'deny' => throw new ContentUnavailableException,
            'explode' => throw new RuntimeException('acme scanner unreachable'),
            default => null,
        };
    }
}
