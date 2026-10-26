<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule;

use RuntimeException;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Drive\Exceptions\Files\ContentUnavailableException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Sharing\ShareContext;

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
