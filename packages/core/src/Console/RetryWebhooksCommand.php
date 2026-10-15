<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Vaulter\Attributes\Internal;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Webhooks\WebhookRetryDispatcher;

/**
 * Re-queues due failed webhook deliveries: it re-dispatches the deliveries whose backoff
 * window has elapsed (status in pending|failed, next_retry_at <= now). The outbox relay
 * (vaulter:relay) only fans events out into deliveries; this command is the retry
 * heartbeat, scheduled every minute.
 *
 * Terminal deliveries (delivered|dead) are never re-queued — a dead row stays in the
 * DLQ for inspection. Every sweep claims through WebhookRetryDispatcher, which locks
 * + re-arms each due row atomically, so overlapping runs never fan a delivery out twice.
 */
#[Internal]
final class RetryWebhooksCommand extends Command
{
    use OpensWriteScope;

    protected $signature = 'vaulter:webhooks:retry';

    protected $description = 'Re-dispatch webhook deliveries whose backoff window has elapsed (§2.11).';

    public function handle(WebhookRetryDispatcher $retries): int
    {
        $count = $retries->redispatchDue();

        $this->components->info("Re-dispatched {$count} due webhook delivery(ies).");

        return self::SUCCESS;
    }
}
