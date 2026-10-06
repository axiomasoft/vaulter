<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Content;

use Vaulter\Content\ScanPolicySpec;
use Vaulter\Models\Drive\Drive;

/** @internal Persisted scan activation is supplied by the drive runtime. */
interface ScanPolicyResolver
{
    /** @return array{ScanPolicySpec, ScanPolicySpec} */
    public function resolve(Drive $drive): array;
}
