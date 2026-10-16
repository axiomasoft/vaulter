<?php

declare(strict_types=1);

namespace Vaulter\Drive\Doctor;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Media\Configuration\DedupScope;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Profiles\ProfileRegistry;

#[Internal]
final readonly class DedupScopeCheck implements DoctorCheck
{
    public function __construct(private MediaConfig $config, private ProfileRegistry $profiles) {}

    public function key(): string
    {
        return 'media.dedup.scope';
    }

    public function title(): string
    {
        return 'Deduplication scope and disks';
    }

    public function run(DoctorContext $context): array
    {
        $scope = $this->config->dedupScope();
        $disks = DiskSet::of($this->config, $this->profiles);
        $evidence = ['scope' => $scope->value, 'disks' => implode(', ', $disks)];

        // A blob is shared by the drives of one tenant: two of them on different disks would point
        // at bytes stored on only one. Per-drive scope and no deduplication never share across drives.
        if ($scope === DedupScope::Tenant && count($disks) > 1) {
            return [DoctorFinding::warning(
                code: 'media.dedup.scope_spans_disks',
                message: 'Profiles store on different disks while deduplication is shared by tenant.',
                hint: 'set vaulter-media.dedup.scope to drive, or give every profile the same disk',
                evidence: $evidence,
            )];
        }

        return [DoctorFinding::ok(code: 'media.dedup.scope_ok', message: 'The deduplication scope fits the configured disks.', evidence: $evidence)];
    }
}
