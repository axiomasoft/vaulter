<?php

declare(strict_types=1);

namespace Vaulter\Media\Doctor;

use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

final readonly class LegacyScopesCheck implements DoctorCheck
{
    public function __construct(private VaulterConfig $config) {}

    public function key(): string
    {
        return 'media.legacy_scopes';
    }

    public function title(): string
    {
        return 'Blobs with a legacy scope';
    }

    public function run(DoctorContext $context): array
    {
        $blob = $this->config->model('blob');
        $count = $blob::query()->whereIn('scope_key', ['legacy:snull', 'legacy:tnull'])->count();

        if ($count === 0) {
            return [DoctorFinding::ok(code: 'media.legacy_scopes_none', message: 'No blob has a legacy scope.')];
        }

        return [DoctorFinding::warning(
            code: 'media.legacy_scopes_found',
            message: $count.' blob(s) still have a legacy scope that mixes tenants or drives.',
            hint: 'run vaulter:blobs:repair-scopes --dry-run, then without it',
            evidence: ['blobs' => $count],
        )];
    }
}
