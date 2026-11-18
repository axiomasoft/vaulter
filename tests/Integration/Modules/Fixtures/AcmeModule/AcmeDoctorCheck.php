<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule;

use Illuminate\Contracts\Config\Repository;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;

/** The module's readiness is its own: an active Acme without an endpoint is misconfigured, and says so. */
final readonly class AcmeDoctorCheck implements DoctorCheck
{
    public function __construct(private Repository $config)
    {
        AcmeProbe::resolved(self::class);
    }

    public function key(): string
    {
        return 'acme.endpoint';
    }

    public function title(): string
    {
        return 'Acme endpoint';
    }

    public function run(DoctorContext $context): array
    {
        $endpoint = $this->config->get('vaulter-acme.endpoint');
        if (!is_string($endpoint) || $endpoint === '') {
            return [DoctorFinding::error('acme.endpoint_missing', 'vaulter-acme.endpoint is not set.', 'Set vaulter-acme.endpoint.')];
        }

        return [DoctorFinding::ok('acme.endpoint_set', 'The endpoint is set.')];
    }
}
