<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Vaulter\Events\Outbox\ConsumerState;
use Vaulter\Events\Outbox\OutboxStore;

/**
 * An outbox consumer detached after its hold left a gap of events it never received: an error until the
 * range is replayed or the loss acknowledged with vaulter:relay:replay. A paused consumer, which still holds
 * retention, is a warning.
 */
#[Internal]
final readonly class OutboxConsumerGapCheck implements DoctorCheck
{
    public function __construct(private OutboxStore $store) {}

    public function key(): string
    {
        return 'core.outbox.consumer_gaps';
    }

    public function title(): string
    {
        return 'Outbox consumer gaps';
    }

    public function run(DoctorContext $context): array
    {
        $findings = [];
        $paused = [];
        foreach ($this->store->consumerRows() as $consumer) {
            foreach ($consumer['gaps'] as $gap) {
                if (($gap['acknowledged_at'] ?? null) !== null) {
                    continue;
                }
                $from = is_numeric($gap['from_seq'] ?? null) ? (int) $gap['from_seq'] : 0;
                $to = is_numeric($gap['to_seq'] ?? null) ? (int) $gap['to_seq'] : 0;
                $events = is_numeric($gap['events'] ?? null) ? (int) $gap['events'] : 0;
                $findings[] = DoctorFinding::error(
                    code: 'core.outbox.consumer_gap',
                    message: sprintf('The outbox consumer %s was detached and missed %d event(s) in seqs [%d, %d].', $consumer['consumer_id'], $events, $from, $to),
                    remediation: sprintf('vaulter:relay:replay --consumer=%s --from=%d --to=%d (or --ack-only to accept the loss)', $consumer['consumer_id'], $from, $to),
                    evidence: ['consumer' => $consumer['consumer_id'], 'from_seq' => $from, 'to_seq' => $to, 'events' => $events],
                );
            }
            if ($consumer['state'] === ConsumerState::Paused->value) {
                $paused[] = $consumer['consumer_id'];
            }
        }
        if ($paused !== []) {
            $findings[] = DoctorFinding::warning(
                code: 'core.outbox.consumer_paused',
                message: 'Paused outbox consumer(s) '.implode(', ', $paused).' hold retention until vaulter.outbox.max_consumer_hold_seconds, then are detached with a gap.',
                remediation: 'enable the module of the consumer again, or let the hold run out',
                evidence: ['paused' => implode(',', $paused)],
            );
        }

        return $findings !== [] ? $findings : [DoctorFinding::ok(code: 'core.outbox.consumers_ok', message: 'No outbox consumer has a gap.')];
    }
}
