<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Events\Outbox\OutboxStore;

/** An outbox delivery leased by a relay that died keeps its lease until the next relay takes it over. */
#[Internal]
final readonly class OutboxLeasesCheck implements DoctorCheck
{
    /** Seconds past the lease expiry before a lease counts as stuck. */
    private const int GRACE = 300;

    public function __construct(private OutboxStore $store) {}

    public function key(): string
    {
        return 'core.outbox.leases';
    }

    public function title(): string
    {
        return 'Outbox relay leases';
    }

    public function run(DoctorContext $context): array
    {
        $cutoff = CarbonImmutable::instance($context->now)->setTimezone(date_default_timezone_get())->subSeconds(self::GRACE);
        $stuck = $this->store->stuckLeases($cutoff);

        if ($stuck > 0) {
            return [DoctorFinding::at(
                severity: $context->strictSeverity(),
                code: 'core.outbox.leases_stuck',
                message: $stuck.' outbox deliver'.($stuck === 1 ? 'y holds' : 'ies hold').' a relay lease that expired more than 5 minutes ago.',
                hint: 'the next vaulter:relay run takes them over; if they stay, check that the relay runs',
                evidence: ['stuck' => $stuck],
            )];
        }

        return [DoctorFinding::ok(code: 'core.outbox.leases_ok', message: 'No stuck outbox lease.')];
    }
}
