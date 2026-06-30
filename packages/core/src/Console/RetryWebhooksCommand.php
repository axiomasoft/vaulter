<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * Re-queues due failed webhook deliveries (master plan §2.11 / D14 / M3b). A focused
 * companion to vaulter:relay-outbox: where the relay both drains the outbox AND
 * re-arms retries, this command ONLY re-dispatches the deliveries whose backoff window
 * has elapsed (status in pending|failed, next_retry_at <= now). Schedulable on its own
 * cadence for a host that runs the relay as a long-lived worker but wants a separate
 * retry heartbeat.
 *
 * Terminal deliveries (delivered|dead) are never re-queued — a dead row stays in the
 * DLQ for inspection. The job is idempotent on re-entry, so an overlap with the relay
 * never double-delivers.
 */
final class RetryWebhooksCommand extends Command
{
    protected $signature = 'vaulter:retry-webhooks';

    protected $description = 'Re-dispatch webhook deliveries whose backoff window has elapsed (§2.11).';

    public function handle(): int
    {
        /** @var class-string<WebhookDelivery> $deliveryClass */
        $deliveryClass = Config::webhookDeliveryModel();

        $due = $deliveryClass::query()->dueForRetry()->get();

        $due->each(static function (WebhookDelivery $delivery): void {
            Bus::dispatch(new DeliverWebhook(deliveryId: Identity::stringify($delivery->getKey())));
        });

        $count = $due->count();

        $this->components->info("Re-dispatched {$count} due webhook delivery(ies).");

        return self::SUCCESS;
    }
}
