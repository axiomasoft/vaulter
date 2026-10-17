<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Inspection;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\Uploads\UploadInspector;
use Vaulter\Drive\Data\Files\UploadFacts;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Profiles\UploadPolicy;
use Vaulter\Kernel;

/** @internal Global and profile allow predicates are both evaluated. */
#[Internal]
final readonly class UploadInspection
{
    public function __construct(private DriveConfig $config, private ProfileRegistry $profiles) {}

    public function maximum(DriveContext $context): ?int
    {
        $global = $this->config->maxFileSize();
        $profile = $this->profiles->get($context->drive->profile)->getDrivePolicy()->maxFileBytes;
        $limits = array_filter([$global > 0 ? $global : null, $profile], static fn (?int $limit): bool => $limit !== null);

        return $limits === [] ? null : min($limits);
    }

    public function inspect(UploadFacts $facts, DriveContext $context): void
    {
        $inspectors = app(Kernel::class)->extensions()->resolve(UploadInspector::class);
        $keys = [];
        foreach ($inspectors as $inspector) {
            if ($inspector->key() === '' || isset($keys[$inspector->key()])) {
                throw new \LogicException('Upload inspectors must have distinct nonempty keys.');
            }
            $keys[$inspector->key()] = true;
        }
        usort($inspectors, static fn (UploadInspector $a, UploadInspector $b): int => ($b->priority() <=> $a->priority()) ?: strcmp($a->key(), $b->key()));
        $global = new UploadPolicy(maxFileSize: $this->config->maxFileSize() ?: null, allowedMimeTypes: $this->config->allowedMimeTypes(), blockedExtensions: $this->config->blockedExtensions());
        $profile = $this->profiles->get($context->drive->profile);
        $uploads = $profile->getUploadPolicy();
        // The profile's file size limit lives in its drive policy.
        $local = new UploadPolicy(maxFileSize: $profile->getDrivePolicy()->maxFileBytes, allowedMimeTypes: $uploads->allowedMimeTypes,
            blockedExtensions: $uploads->blockedExtensions, requireScan: $uploads->requireScan, scanMode: $uploads->scanMode,
            scanPolicyVersion: $uploads->scanPolicyVersion);
        foreach ([$global, $local] as $policy) {
            foreach ($inspectors as $inspector) {
                $inspector->inspect($facts, $policy, $context);
            }
        }
    }
}
