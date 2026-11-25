<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Content;

use Packman\Attributes\Internal;
use Vaulter\Drive\Content\ScanPolicySpec;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\FileRevision;

/** Persisted scan activation is supplied by the drive runtime. */
#[Internal]
interface ScanPolicyResolver
{
    /** @return array{ScanPolicySpec, ScanPolicySpec} */
    public function resolve(Drive $drive): array;

    /** @return list<ScanPolicySpec> */
    public function forDrive(Drive $drive): array;

    public function required(FileRevision $revision, ScanPolicySpec $spec): bool;

    /**
     * The current specs of `$drive` under which `$revision` must be scanned: forDrive() and required() for every
     * spec at once, over rows the caller has just read (the delivery check reads them once and asks one question).
     *
     * @return list<ScanPolicySpec>
     */
    public function requiredSpecs(Drive $drive, FileRevision $revision): array;
}
