<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Carbon\CarbonImmutable;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Vaulter\Configuration\OutboxOptions;
use Vaulter\Events\Outbox\OutboxStore;

/** Dead-lettered outbox deliveries a consumer gave up on and retention still holds: replay them or accept the loss. */
#[Internal]
final readonly class OutboxDeadLettersCheck implements DoctorCheck
{
    public function __construct(private OutboxStore $store, private OutboxOptions $config) {}

    public function key(): string
    {
        return 'core.outbox.dead';
    }

    public function title(): string
    {
        return 'Outbox dead letters';
    }

    public function run(DoctorContext $context): array
    {
        // Rows are written in the application's timezone; compare in it.
        $now = CarbonImmutable::instance($context->now)->setTimezone(date_default_timezone_get());
        $dead = $this->store->deadRows($now->subSeconds($this->config->deadRetentionSeconds));

        if ($dead !== []) {
            ksort($dead);

            return [DoctorFinding::warning(
                code: 'core.outbox.dead_letters',
                message: array_sum($dead).' dead outbox deliveries wait for a decision: '.implode(', ', array_map(static fn (string $consumer, int $rows): string => $consumer.' '.$rows, array_keys($dead), $dead)).'.',
                remediation: 'inspect last_error of the dead rows, then vaulter:relay:replay --consumer --from --to, or let dead retention drop them',
                evidence: ['dead_rows' => array_sum($dead), 'consumers' => implode(',', array_keys($dead))],
            )];
        }

        return [DoctorFinding::ok(code: 'core.outbox.no_dead_letters', message: 'The outbox has no dead deliveries.')];
    }
}
