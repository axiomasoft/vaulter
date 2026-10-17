<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Drive\Exceptions\Files\ContentUnavailableException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Sharing\ShareContext;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Kernel;

/**
 * Runs every active {@see DownloadGuard} on each delivery path, in the order of the slot. The first guard
 * that throws denies; a guard that throws anything but a problem is a denial too, never a pass.
 */
#[Internal]
final readonly class DownloadGuards
{
    public function __construct(private Kernel $kernel) {}

    public function check(DriveContext|ShareContext $context, Node $node, FileRevision $revision): void
    {
        foreach ($this->kernel->extensions()->resolve(DownloadGuard::class) as $guard) {
            try {
                $guard->check($context, $node, $revision);
            } catch (ProblemException $denied) {
                throw $denied;
            } catch (Throwable) {
                throw new ContentUnavailableException;
            }
        }
    }
}
