<?php

declare(strict_types=1);

namespace Vaulter\Content;

use Throwable;
use Vaulter\Contracts\Drive\DownloadGuard;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\Files\ContentUnavailableException;
use Vaulter\Kernel;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;
use Vaulter\Sharing\ShareContext;

/**
 * Runs every active {@see DownloadGuard} on each delivery path, in the order of the slot. The first guard
 * that throws denies; a guard that throws anything but a problem is a denial too, never a pass.
 *
 * @internal
 */
final readonly class DownloadGuards
{
    public function __construct(private Kernel $kernel) {}

    public function check(OperationContext|ShareContext $context, Node $node, FileRevision $revision): void
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
