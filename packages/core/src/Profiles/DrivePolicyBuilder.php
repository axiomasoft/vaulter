<?php

declare(strict_types=1);

namespace Vaulter\Profiles;

use Vaulter\Attributes\Api;

/** The fluent half of Profile::drivePolicy(); build() validates the result. */
#[Api]
final class DrivePolicyBuilder
{
    private string $namePolicy;

    private int $maxFolderDepth;

    private int $maxFolderChildren;

    private ?int $maxFileBytes;

    private int $maxPropertiesBytes;

    private int $maxPropertyCount;

    private ?int $maxShareLinkTtlDays;

    private RevisionRetentionPolicy $revisionRetention;

    public function __construct(DrivePolicy $from = new DrivePolicy)
    {
        $this->namePolicy = $from->namePolicy;
        $this->maxFolderDepth = $from->maxFolderDepth;
        $this->maxFolderChildren = $from->maxFolderChildren;
        $this->maxFileBytes = $from->maxFileBytes;
        $this->maxPropertiesBytes = $from->maxPropertiesBytes;
        $this->maxPropertyCount = $from->maxPropertyCount;
        $this->maxShareLinkTtlDays = $from->maxShareLinkTtlDays;
        $this->revisionRetention = $from->revisionRetention;
    }

    public function namePolicy(string $key): static
    {
        $this->namePolicy = $key;

        return $this;
    }

    public function maxFolderDepth(int $depth): static
    {
        $this->maxFolderDepth = $depth;

        return $this;
    }

    public function maxFolderChildren(int $children): static
    {
        $this->maxFolderChildren = $children;

        return $this;
    }

    public function maxFileBytes(?int $bytes): static
    {
        $this->maxFileBytes = $bytes;

        return $this;
    }

    public function maxPropertiesBytes(int $bytes): static
    {
        $this->maxPropertiesBytes = $bytes;

        return $this;
    }

    public function maxPropertyCount(int $count): static
    {
        $this->maxPropertyCount = $count;

        return $this;
    }

    public function maxShareLinkTtlDays(?int $days): static
    {
        $this->maxShareLinkTtlDays = $days;

        return $this;
    }

    public function revisionRetention(RevisionRetentionPolicy $policy): static
    {
        $this->revisionRetention = $policy;

        return $this;
    }

    public function build(): DrivePolicy
    {
        return new DrivePolicy(
            namePolicy: $this->namePolicy,
            maxFolderDepth: $this->maxFolderDepth,
            maxFolderChildren: $this->maxFolderChildren,
            maxFileBytes: $this->maxFileBytes,
            maxPropertiesBytes: $this->maxPropertiesBytes,
            maxPropertyCount: $this->maxPropertyCount,
            maxShareLinkTtlDays: $this->maxShareLinkTtlDays,
            revisionRetention: $this->revisionRetention,
        );
    }
}
