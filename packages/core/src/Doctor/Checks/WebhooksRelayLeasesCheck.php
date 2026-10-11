<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Carbon\CarbonImmutable;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

/** An outbox row claimed by a relay that died keeps its lease: its events are never fanned out. */
final readonly class WebhooksRelayLeasesCheck implements DoctorCheck
{
    /** Seconds past the lease expiry before a claim counts as stuck. */
    private const int GRACE = 300;

    public function __construct(private VaulterConfig $config) {}

    public function key(): string
    {
        return 'core.webhooks.relay_leases';
    }

    public function title(): string
    {
        return 'Webhook relay leases';
    }

    public function run(DoctorContext $context): array
    {
        if (!$this->config->featureEnabled('webhooks')) {
            return [DoctorFinding::ok(code: 'core.webhooks.relay_leases_skipped', message: 'Webhooks are disabled.', evidence: ['skipped' => true])];
        }

        $cutoff = CarbonImmutable::instance($context->now)->setTimezone(date_default_timezone_get())->subSeconds(self::GRACE);
        $outbox = $this->config->model('outbox');
        $stuck = $outbox::query()->where('status', 'pending')->whereNotNull('claim_until')->where('claim_until', '<', $cutoff)->count();

        if ($stuck > 0) {
            return [DoctorFinding::at(
                severity: $context->strictSeverity(),
                code: 'core.webhooks.relay_leases_stuck',
                message: $stuck.' outbox row(s) hold a relay lease that expired more than 5 minutes ago.',
                hint: 'the next vaulter:webhooks:relay run takes them over; if they stay, check that the relay runs',
                evidence: ['stuck' => $stuck],
            )];
        }

        return [DoctorFinding::ok(code: 'core.webhooks.relay_leases_ok', message: 'No stuck relay lease.')];
    }
}
