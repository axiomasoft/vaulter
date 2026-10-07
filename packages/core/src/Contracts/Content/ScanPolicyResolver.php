<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Content;

use Vaulter\Content\ScanPolicySpec;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\FileRevision;

/** @internal Persisted scan activation is supplied by the drive runtime. */
interface ScanPolicyResolver
{
    /** @return array{ScanPolicySpec, ScanPolicySpec} */
    public function resolve(Drive $drive): array;

    /** @return list<ScanPolicySpec> */
    public function forDrive(Drive $drive): array;

    public function required(FileRevision $revision, ScanPolicySpec $spec): bool;
}
