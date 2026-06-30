<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;
use Vaulter\Webhooks\OutboxRelay;

/**
 * Drains the transactional outbox into webhook deliveries and re-arms due retries
 * (master plan §2.11 / D14 / M3b) — the tick of the reliable webhook channel. Run it
 * on a short schedule or as a long-running worker; it is idempotent (row-state +
 * event_id), so overlapping ticks never double-deliver.
 *
 * Each tick: re-arm due retries FIRST (so the sweep only sees deliveries already due
 * at tick start), then OutboxRelay::process() fans pending outbox rows out into
 * per-endpoint deliveries + jobs. The due-retry sweep is the SINGLE owner of retries —
 * DeliverWebhook never self-releases. Schedule it withoutOverlapping() so two ticks
 * can't re-arm the same due row at once.
 */
final class RelayOutboxCommand extends Command
{
    protected $signature = 'vaulter:relay-outbox {--limit= : Max outbox rows to drain this tick}';

    protected $description = 'Relay pending outbox events to webhook endpoints and re-dispatch due retries (§2.11).';

    public function handle(OutboxRelay $relay): int
    {
        $retried = $this->redispatchDueRetries();
        $relayed = $relay->process(limit: $this->limit());

        $this->components->info("Relayed {$relayed} outbox event(s), re-dispatched {$retried} due retry(ies).");

        return self::SUCCESS;
    }

    /**
     * Re-dispatch deliveries that are pending or have failed and whose backoff window
     * has elapsed (next_retry_at <= now). Terminal deliveries (delivered|dead) are
     * skipped; the job itself is idempotent on re-entry.
     */
    private function redispatchDueRetries(): int
    {
        /** @var class-string<WebhookDelivery> $deliveryClass */
        $deliveryClass = Config::webhookDeliveryModel();

        $due = $deliveryClass::query()->dueForRetry()->get();

        $due->each(static function (WebhookDelivery $delivery): void {
            Bus::dispatch(new DeliverWebhook(deliveryId: Identity::stringify($delivery->getKey())));
        });

        return $due->count();
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
