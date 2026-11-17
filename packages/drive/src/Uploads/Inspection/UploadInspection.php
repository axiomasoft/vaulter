<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Inspection;

use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveOptions;
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
    public function __construct(private DriveOptions $config, private ProfileRegistry $profiles) {}

    public function maximum(DriveContext $context): ?int
    {
        $global = $this->config->uploads->maxFileSize;
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
                throw new LogicException('Upload inspectors must have distinct nonempty keys.');
            }
            $keys[$inspector->key()] = true;
        }
        usort($inspectors, static function (UploadInspector $a, UploadInspector $b): int {
            $cmp = $b->priority() <=> $a->priority();

            return $cmp !== 0 ? $cmp : strcmp($a->key(), $b->key());
        });
        $global = new UploadPolicy(maxFileSize: $this->config->uploads->maxFileSize !== 0 ? $this->config->uploads->maxFileSize : null, allowedMimeTypes: $this->config->uploads->allowedMimeTypes, blockedExtensions: $this->config->uploads->blockedExtensions);
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
