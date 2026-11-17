<?php

declare(strict_types=1);

namespace Vaulter\Drive\Policies;

use Packman\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Profiles\Profile;

/** Admission policy: each MIME allowlist contributes an independent predicate. */
#[Internal]
final readonly class UploadPolicy
{
    public function __construct(private DriveOptions $config) {}

    public function maxFileSize(Profile $profile): int
    {
        return min($this->config->uploads->maxFileSize, $profile->getDrivePolicy()->maxFileBytes ?? PHP_INT_MAX);
    }

    public function mimeAllowed(Profile $profile, string $mime): bool
    {
        return $this->matches($this->config->uploads->allowedMimeTypes, $mime)
            && $this->matches($profile->getUploadPolicy()->allowedMimeTypes, $mime);
    }

    /** @return list<string> */
    public function blockedExtensions(Profile $profile): array
    {
        return array_values(array_unique(array_map(
            static fn (string $extension): string => strtolower(ltrim($extension, '.')),
            [...$this->config->uploads->blockedExtensions, ...$profile->getUploadPolicy()->blockedExtensions],
        )));
    }

    public function assertAllowed(Profile $profile, int $size, string $mime, string $extension): void
    {
        if ($size < 0 || $size > $this->maxFileSize($profile)
            || !$this->mimeAllowed($profile, $mime)
            || in_array(strtolower(ltrim($extension, '.')), $this->blockedExtensions($profile), true)) {
            throw ProfileRestrictionException::forOperation('upload');
        }
    }

    /** @param list<string> $patterns */
    private function matches(array $patterns, string $mime): bool
    {
        if ($patterns === []) {
            return true;
        }

        return array_any($patterns, fn ($pattern): bool => strtolower($pattern) === strtolower($mime)
            || (str_ends_with($pattern, '/*') && str_starts_with(strtolower($mime), strtolower(substr($pattern, 0, -1)))));
    }
}
