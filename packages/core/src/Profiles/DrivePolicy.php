<?php

declare(strict_types=1);

namespace Vaulter\Profiles;

use Vaulter\Exceptions\Profiles\InvalidProfileException;
use Vaulter\Naming\NamePolicy;
use Vaulter\Naming\PermissiveNamePolicy;
use Vaulter\Naming\PortableNamePolicy;

/**
 * The tree and content limits of every drive of a profile, checked by
 * the Actions under the locks of the rows they change, on every path: HTTP, PHP,
 * system, import and restore. Lowering a limit deletes nothing; it only refuses
 * operations that would make an existing excess worse.
 *
 * maxShareLinkTtlDays is enforced by share links; revisionRetention by
 * the revision pruning.
 */
final readonly class DrivePolicy
{
    public function __construct(
        public string $namePolicy = PortableNamePolicy::KEY,
        public int $maxFolderDepth = 100,
        public int $maxFolderChildren = 10_000,
        public ?int $maxFileBytes = null,
        public int $maxPropertiesBytes = 16_384,
        public int $maxPropertyCount = 100,
        public ?int $maxShareLinkTtlDays = null,
        public RevisionRetentionPolicy $revisionRetention = new RevisionRetentionPolicy,
    ) {
        if (!in_array($namePolicy, [PortableNamePolicy::KEY, PermissiveNamePolicy::KEY], true)) {
            throw new InvalidProfileException('Unknown name policy "'.$namePolicy.'"; use portable-v1 or permissive-v1.');
        }
        foreach ([$maxFolderDepth, $maxFolderChildren, $maxPropertiesBytes, $maxPropertyCount] as $limit) {
            if ($limit < 1) {
                throw new InvalidProfileException('Drive policy limits must be positive.');
            }
        }
        if (($maxFileBytes !== null && $maxFileBytes < 0) || ($maxShareLinkTtlDays !== null && $maxShareLinkTtlDays < 1)) {
            throw new InvalidProfileException('Drive policy optional limits must be null or positive.');
        }
    }

    public function names(): NamePolicy
    {
        return $this->namePolicy === PermissiveNamePolicy::KEY ? new PermissiveNamePolicy : new PortableNamePolicy;
    }
}
