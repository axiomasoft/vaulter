<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Vaulter\Webhooks\WebhookRetryDispatcher;

/**
 * Re-queues due failed webhook deliveries (master plan §2.11 / D14 / M3b). A focused
 * companion to vaulter:relay-outbox: where the relay both drains the outbox AND
 * re-arms retries, this command ONLY re-dispatches the deliveries whose backoff window
 * has elapsed (status in pending|failed, next_retry_at <= now). Schedulable on its own
 * cadence for a host that runs the relay as a long-lived worker but wants a separate
 * retry heartbeat.
 *
 * Terminal deliveries (delivered|dead) are never re-queued — a dead row stays in the
 * DLQ for inspection. Both this command and the relay claim through the SAME
 * WebhookRetryDispatcher (F-P1.7-05), which locks + re-arms each due row atomically, so
 * running both never fans a delivery out twice.
 */
final class RetryWebhooksCommand extends Command
{
    protected $signature = 'vaulter:retry-webhooks';

    protected $description = 'Re-dispatch webhook deliveries whose backoff window has elapsed (§2.11).';

    public function handle(WebhookRetryDispatcher $retries): int
    {
        $count = $retries->redispatchDue();

        $this->components->info("Re-dispatched {$count} due webhook delivery(ies).");

        return self::SUCCESS;
    }
}
