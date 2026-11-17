<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Carbon\CarbonImmutable;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Vaulter\Events\Outbox\OutboxStore;

/** An outbox consumer whose oldest due delivery waits too long: the relay does not run or cannot keep up. */
#[Internal]
final readonly class OutboxLagCheck implements DoctorCheck
{
    /** Seconds the oldest due delivery may wait for the relay before the lag is reported. */
    private const int MAX_LAG = 600;

    public function __construct(private OutboxStore $store) {}

    public function key(): string
    {
        return 'core.outbox.lag';
    }

    public function title(): string
    {
        return 'Outbox lag';
    }

    public function run(DoctorContext $context): array
    {
        // Rows are written in the application's timezone; compare in it.
        $now = CarbonImmutable::instance($context->now)->setTimezone(date_default_timezone_get());
        $lagging = [];
        foreach ($this->store->oldestOpen($now) as $consumer => $oldest) {
            $lag = max(0, $now->getTimestamp() - $oldest->getTimestamp());
            if ($lag >= self::MAX_LAG) {
                $lagging[$consumer] = $lag;
            }
        }

        if ($lagging !== []) {
            return [DoctorFinding::warning(
                code: 'core.outbox.lagging',
                message: 'The oldest due outbox delivery of '.implode(', ', array_keys($lagging)).' has waited '.intdiv(max($lagging), 60).' minutes for the relay.',
                remediation: 'run the scheduler (vaulter:relay) and the queue worker',
                evidence: ['lag_seconds' => max($lagging), 'consumers' => implode(',', array_keys($lagging)), 'limit_seconds' => self::MAX_LAG],
            )];
        }

        return [DoctorFinding::ok(code: 'core.outbox.current', message: 'The outbox is relayed in time.')];
    }
}
