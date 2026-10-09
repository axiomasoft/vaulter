<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;
use Vaulter\Actions\Webhooks\RedeliverWebhookAction;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Exceptions\Webhooks\WebhookDeliveryNotRedeliverableException;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Support\Identity;

/**
 * Sends finished webhook deliveries again — one by id, or every delivery of a status
 * (dead by default) filtered by endpoint and age. Each one goes through
 * RedeliverWebhookAction, so the receiver gets the same webhook-id and an inactive
 * endpoint is refused. Only rows still inside the retention window exist to redeliver.
 */
final class RedeliverWebhooksCommand extends Command
{
    use OpensWriteScope;

    private const int CHUNK = 200;

    protected $signature = 'vaulter:webhooks:redeliver
        {delivery? : Id of one delivery to send again}
        {--endpoint= : Only deliveries of this endpoint id}
        {--since= : Only deliveries created at or after this date/time}
        {--status=dead : delivered or dead}';

    protected $description = 'Send finished webhook deliveries again (same webhook-id).';

    public function handle(RedeliverWebhookAction $redeliver, VaulterConfig $config): int
    {
        if (!$config->featureEnabled('webhooks')) {
            $this->components->info('Webhooks feature is off — nothing to redeliver.');

            return self::SUCCESS;
        }

        $status = $this->option('status');
        if (!in_array($status, ['dead', 'delivered'], true)) {
            $this->components->error('--status must be dead or delivered.');

            return self::FAILURE;
        }

        try {
            $since = is_string($this->option('since')) && $this->option('since') !== '' ? Carbon::parse($this->option('since')) : null;
        } catch (Throwable) {
            $this->components->error('--since must be a date or date-time.');

            return self::FAILURE;
        }

        /** @var class-string<WebhookDelivery> $model */
        $model = $config->model('webhook_delivery');
        $query = $model::query();
        $given = $this->argument('delivery');

        if (is_string($given) && $given !== '') {
            $query->whereKey($given);
        } else {
            $query->where('status', $status);
            if (is_string($this->option('endpoint')) && $this->option('endpoint') !== '') {
                $query->where('endpoint_id', $this->option('endpoint'));
            }
            if ($since instanceof Carbon) {
                $query->where('created_at', '>=', $since);
            }
        }

        $sent = 0;
        $refused = 0;
        $found = 0;

        $query->orderBy($query->getModel()->getKeyName())->chunkById(self::CHUNK, function ($chunk) use ($redeliver, &$sent, &$refused, &$found): void {
            /** @var iterable<WebhookDelivery> $chunk */
            foreach ($chunk as $delivery) {
                $found++;
                try {
                    $redeliver->execute($delivery);
                    $sent++;
                } catch (WebhookDeliveryNotRedeliverableException $exception) {
                    $refused++;
                    $this->components->warn(sprintf('Delivery %s skipped: %s', Identity::stringify($delivery->getKey()), $exception->getMessage()));
                }
            }
        });

        if ($found === 0) {
            $this->components->info('No matching deliveries.');

            return is_string($given) && $given !== '' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->info(sprintf('Redelivered %d of %d deliver%s.', $sent, $found, $found === 1 ? 'y' : 'ies'));

        return $refused > 0 && $sent === 0 ? self::FAILURE : self::SUCCESS;
    }
}
