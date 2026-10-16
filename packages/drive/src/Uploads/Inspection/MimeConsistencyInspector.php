<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Inspection;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\Uploads\UploadInspector;
use Vaulter\Drive\Data\Files\UploadFacts;
use Vaulter\Drive\Exceptions\Files\UploadRejectedException;
use Vaulter\Operations\DriveContext;
use Vaulter\Profiles\UploadPolicy;

#[Internal]
final readonly class MimeConsistencyInspector implements UploadInspector
{
    public function key(): string
    {
        return 'MimeConsistencyInspector';
    }

    public function priority(): int
    {
        return 80;
    }

    public function inspect(UploadFacts $facts, UploadPolicy $policy, DriveContext $context): void
    {
        $detected = $facts->detectedMimeType;
        if ($policy->allowedMimeTypes !== [] && ($detected === null || !array_any($policy->allowedMimeTypes, static fn (string $allowed): bool => $allowed === $detected || (str_ends_with($allowed, '/*') && str_starts_with($detected, substr($allowed, 0, -1)))))) {
            throw new UploadRejectedException('mime_not_allowed');
        }
        if (!app(DriveConfig::class)->rejectMimeMismatch() || $detected === null) {
            return;
        }
        $declared = strtolower(trim(explode(';', $facts->declaredMimeType ?? '')[0]));
        $declared = $declared === 'image/jpg' ? 'image/jpeg' : $declared;
        $generic = ['', 'application/octet-stream', 'inode/x-empty', 'text/plain'];
        $extensions = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'zip' => 'application/zip'];
        foreach ([$declared, $extensions[$facts->extension] ?? ''] as $expected) {
            if (!in_array($expected, $generic, true) && !in_array($detected, $generic, true) && $detected !== 'text/plain' && $expected !== $detected) {
                throw new UploadRejectedException('mime_mismatch');
            }
        }
    }
}
