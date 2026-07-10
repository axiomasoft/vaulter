<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Vaulter\Webhooks\OutboxRelay;
use Vaulter\Webhooks\WebhookRetryDispatcher;

/**
 * Drains the transactional outbox into webhook deliveries and re-arms due retries
 * (master plan §2.11 / D14 / M3b) — the tick of the reliable webhook channel. Run it
 * on a short schedule or as a long-running worker; it is idempotent (row-state +
 * event_id), so overlapping ticks never double-deliver.
 *
 * Each tick: re-arm due retries FIRST (so the sweep only sees deliveries already due
 * at tick start), then OutboxRelay::process() fans pending outbox rows out into
 * per-endpoint deliveries + jobs. The due-retry sweep is the SINGLE owner of retries
 * (WebhookRetryDispatcher claims each row atomically, F-P1.7-05) — DeliverWebhook never
 * self-releases. Still schedule it withoutOverlapping() for tidiness.
 */
final class RelayOutboxCommand extends Command
{
    protected $signature = 'vaulter:relay-outbox {--limit= : Max outbox rows to drain this tick}';

    protected $description = 'Relay pending outbox events to webhook endpoints and re-dispatch due retries (§2.11).';

    public function handle(OutboxRelay $relay, WebhookRetryDispatcher $retries): int
    {
        $retried = $retries->redispatchDue();
        $relayed = $relay->process(limit: $this->limit());

        $this->components->info("Relayed {$relayed} outbox event(s), re-dispatched {$retried} due retry(ies).");

        return self::SUCCESS;
    }

    private function limit(): int
    {
        $limit = $this->option('limit');

        if (is_numeric($limit) && (int) $limit > 0) {
            return (int) $limit;
        }

        return 100;
    }
}
