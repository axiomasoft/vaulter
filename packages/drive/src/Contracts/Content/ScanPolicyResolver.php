<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Content;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Content\ScanPolicySpec;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\FileRevision;

/** Persisted scan activation is supplied by the drive runtime. */
#[Internal]
interface ScanPolicyResolver
{
    /** @return array{ScanPolicySpec, ScanPolicySpec} */
    public function resolve(Drive $drive): array;

    /** @return list<ScanPolicySpec> */
    public function forDrive(Drive $drive): array;

    public function required(FileRevision $revision, ScanPolicySpec $spec): bool;
}
