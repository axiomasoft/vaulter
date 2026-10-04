<?php

declare(strict_types=1);

namespace Vaulter\Load\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;
use Vaulter\Webhooks\OutboxRelay;
use Vaulter\Webhooks\WebhookRetryDispatcher;

/**
 * Live P6.3 probe: seed, relay, retry and exact cleanup on the disposable stand.
 */
final class QueueProbeCommand extends Command
{
    protected $signature = 'load:queue-probe
        {action : config|counts|reset|endpoints|density|outbox|due|relay|retry|force-due|duplicate|barrier|delivery}
        {--run-id= : Run prefix stored in event ids and endpoint URLs}
        {--cell-id= : Cell stamped into the sink URL}
        {--count=0 : Rows or endpoints to insert}
        {--density=1 : Subscription density 0, 1 or all}
        {--profile=2xx : Sink path profile}
        {--delay-ms= : Optional delay_ms query for the delay profile}
        {--limit=100 : Relay claim limit}
        {--delivery-id= : Existing delivery id}
        {--kind=relay : Barrier tick kind: relay or retry}
        {--parties=4 : Barrier party count}';

    protected $description = 'Seed and measure the disposable P6 queue stand.';

    public function handle(OutboxRelay $relay, WebhookRetryDispatcher $retries): int
    {
        try {
            $payload = match ((string) $this->argument('action')) {
                'config' => $this->configPayload(),
                'counts' => $this->counts(),
                'reset' => $this->reset(),
                'endpoints' => $this->endpoints(),
                'density' => $this->density(),
                'outbox' => $this->outbox(),
                'due' => $this->due(),
                'relay' => $this->relay($relay),
                'retry' => $this->retry(),
                'force-due' => $this->forceDue(),
                'duplicate' => $this->duplicate(),
                'barrier' => $this->barrier($relay, $retries),
                'delivery' => $this->delivery(),
                default => throw new RuntimeException('Unknown queue probe action.'),
            };
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage()."\n");

            return self::FAILURE;
        }

        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function configPayload(): array
    {
        $retryAfter = config('queue.connections.redis.retry_after');

        return [
            'laravel' => app()->version(),
            'retry_after' => is_numeric($retryAfter) ? (int) $retryAfter : null,
            'webhook_timeout' => Config::webhookTimeout(),
            'webhook_connect_timeout' => Config::webhookConnectTimeout(),
            'webhook_max_attempts' => Config::webhookMaxAttempts(),
            'webhook_backoff_1' => Config::webhookBackoff(1),
            'webhook_disable_after' => Config::webhookDisableAfterFailures(),
            'worker_timeout' => 60,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $outbox = new Outbox;
        $endpoint = new WebhookEndpoint;
        $delivery = new WebhookDelivery;
        $connection = $outbox->getConnection();

        return [
            'outbox_pending' => (int) $connection->table($outbox->getTable())->where('status', 'pending')->count(),
            'outbox_dispatched' => (int) $connection->table($outbox->getTable())->where('status', 'dispatched')->count(),
            'endpoints_active' => (int) $connection->table($endpoint->getTable())->where('is_active', true)->count(),
            'deliveries' => (int) $connection->table($delivery->getTable())->count(),
            'deliveries_due' => (int) $delivery->newQuery()->dueForRetry()->count(),
            'deliveries_failed' => (int) $connection->table($delivery->getTable())->where('status', 'failed')->count(),
            'deliveries_dead' => (int) $connection->table($delivery->getTable())->where('status', 'dead')->count(),
            'deliveries_delivered' => (int) $connection->table($delivery->getTable())->where('status', 'delivered')->count(),
            'failed_jobs' => (int) DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function reset(): array
    {
        $runId = $this->runId();
        $like = '%'.$runId.'%';
        $outbox = new Outbox;
        $endpoint = new WebhookEndpoint;
        $delivery = new WebhookDelivery;
        $connection = $outbox->getConnection();
        $deliveries = (int) $connection->table($delivery->getTable())
            ->whereRaw('payload::text like ?', [$like])
            ->delete();
        $outboxRows = (int) $connection->table($outbox->getTable())
            ->where('event_id', 'like', $this->prefix().'%')
            ->delete();
        $endpoints = (int) $connection->table($endpoint->getTable())
            ->where('url', 'like', $like)
            ->delete();

        return ['outbox' => $outboxRows, 'endpoints' => $endpoints, 'deliveries' => $deliveries];
    }

    /**
     * @return array<string, int>
     */
    private function endpoints(): array
    {
        $count = max(1, (int) $this->option('count'));
        $runId = $this->runId();
        $created = 0;
        $endpoint = new WebhookEndpoint;
        $existing = (int) $endpoint->newQuery()->where('url', 'like', '%'.$runId.'%')->count();

        if ($existing >= $count && (string) $this->option('profile') === '') {
            return ['endpoints' => $existing];
        }

        for ($index = $existing + 1; $index <= $count; $index++) {
            $row = new WebhookEndpoint;
            $row->forceFill([
                'url' => $this->url($index),
                'events' => ['load.queue'],
                'signing_secret' => 'p6-stand-'.$runId,
                'is_active' => true,
                'failure_count' => 0,
            ])->save();
            $created++;
        }

        return ['endpoints' => $existing + $created];
    }

    /**
     * @return array<string, int|string>
     */
    private function density(): array
    {
        $density = (string) $this->option('density');
        $endpoint = new WebhookEndpoint;
        $rows = $endpoint->newQuery()->where('url', 'like', '%'.$this->runId().'%')->orderBy('id')->get();
        $subscribed = match ($density) {
            '0' => 0,
            '1' => min(1, $rows->count()),
            'all' => $rows->count(),
            default => throw new RuntimeException('Density must be 0, 1 or all.'),
        };
        $index = 0;

        foreach ($rows as $row) {
            $row->forceFill([
                'events' => $index < $subscribed ? ['load.queue'] : ['load.other'],
                'is_active' => true,
                'url' => $this->url($index + 1),
            ])->save();
            $index++;
        }

        return ['density' => $density, 'subscribed' => $subscribed, 'endpoints' => $rows->count()];
    }

    /**
     * @return array<string, int>
     */
    private function outbox(): array
    {
        $count = max(0, (int) $this->option('count'));
        $model = new Outbox;
        $table = $model->getConnection()->table($model->getTable());
        $now = now();
        $inserted = 0;

        while ($inserted < $count) {
            $rows = [];
            $batch = min(500, $count - $inserted);

            for ($index = 0; $index < $batch; $index++) {
                $eventId = $this->prefix().'outbox-'.Str::lower((string) Str::ulid());
                $rows[] = [
                    'event_id' => $eventId,
                    'panel' => 'default',
                    'topic' => null,
                    'event' => 'load.queue',
                    'payload' => json_encode(['event_id' => $eventId], JSON_THROW_ON_ERROR),
                    'available_at' => $now,
                    'dispatched_at' => null,
                    'attempts' => 0,
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $inserted++;
            }

            $table->insert($rows);
        }

        return ['inserted' => $inserted];
    }

    /**
     * @return array<string, int|string>
     */
    private function due(): array
    {
        $count = max(0, (int) $this->option('count'));
        $endpointId = $this->ensureProbeEndpoint();
        $delivery = new WebhookDelivery;
        $table = $delivery->getConnection()->table($delivery->getTable());
        $now = now()->subMinute();
        $inserted = 0;

        while ($inserted < $count) {
            $rows = [];
            $batch = min(500, $count - $inserted);

            for ($index = 0; $index < $batch; $index++) {
                $eventId = $this->prefix().'due-'.Str::lower((string) Str::ulid());
                $rows[] = [
                    'endpoint_id' => $endpointId,
                    'event' => 'load.queue',
                    'payload' => json_encode(['event_id' => $eventId], JSON_THROW_ON_ERROR),
                    'status' => 'failed',
                    'attempts' => 1,
                    'next_retry_at' => $now,
                    'last_error' => 'probe',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $inserted++;
            }

            $table->insert($rows);
        }

        return ['inserted' => $inserted, 'endpoint_id' => (string) $endpointId];
    }

    /**
     * @return array<string, int>
     */
    private function relay(OutboxRelay $relay): array
    {
        $started = hrtime(true);

        return [
            'relayed' => $relay->process(limit: max(1, (int) $this->option('limit'))),
            'elapsed_ns' => hrtime(true) - $started,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function retry(): array
    {
        $started = hrtime(true);
        $count = 0;
        $status = 0;

        try {
            // Run the product command through the console kernel so CommandFinished
            // records its memory seam. Keep its text separate from the probe JSON.
            $output = new BufferedOutput;
            $status = Artisan::call('vaulter:retry-webhooks', [], $output);
            if ($status !== self::SUCCESS || preg_match('/Re-dispatched (\d+) due webhook delivery\(ies\)\./', $output->fetch(), $matches) !== 1) {
                throw new RuntimeException('Retry command failed or did not report its dispatch count.');
            }
            $count = (int) $matches[1];
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage()."\n");
            $status = 1;
        }

        return [
            'retried' => $count,
            'elapsed_ns' => hrtime(true) - $started,
            'peak_bytes' => memory_get_peak_usage(true),
            'status' => $status,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function forceDue(): array
    {
        $delivery = $this->findDelivery();
        $before = $delivery->next_retry_at?->toIso8601String();
        $delivery->forceFill(['next_retry_at' => now()->subSecond()])->save();

        return [
            'delivery_id' => Identity::stringify($delivery->getKey()),
            'before' => $before,
            'status' => (string) $delivery->status,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function duplicate(): array
    {
        $delivery = (string) $this->option('delivery-id') !== ''
            ? $this->findDelivery()
            : $this->makeDelivery(attempts: 0, status: 'pending');
        $id = Identity::stringify($delivery->getKey());
        $times = (int) $this->option('count');

        if ($times < 1) {
            $times = 2;
        }

        for ($index = 0; $index < $times; $index++) {
            Bus::dispatch(new DeliverWebhook(deliveryId: $id));
        }

        return ['delivery_id' => $id, 'dispatches' => $times];
    }

    /**
     * @return array<string, int|string|float>
     */
    private function barrier(OutboxRelay $relay, WebhookRetryDispatcher $retries): array
    {
        $parties = max(2, (int) $this->option('parties'));
        $kind = (string) $this->option('kind');
        $connection = DB::connection();
        $runId = $this->runId();
        $connection->statement(
            'INSERT INTO p6_barrier (run_id, waiting) VALUES (?, 0) ON CONFLICT (run_id) DO NOTHING',
            [$runId],
        );
        $connection->statement('UPDATE p6_barrier SET waiting = waiting + 1 WHERE run_id = ?', [$runId]);
        $deadline = microtime(true) + 20.0;
        $waiting = 0;

        while (microtime(true) < $deadline) {
            $waiting = (int) $connection->table('p6_barrier')->where('run_id', $runId)->value('waiting');

            if ($waiting >= $parties) {
                break;
            }

            usleep(20000);
        }

        if ($waiting < $parties) {
            throw new RuntimeException('Barrier did not fill.');
        }

        $started = microtime(true);
        $count = $kind === 'retry'
            ? $retries->redispatchDue()
            : $relay->process(limit: max(1, (int) $this->option('limit')));

        return ['kind' => $kind, 'waiting' => $waiting, 'count' => $count, 'started' => $started];
    }

    /**
     * @return array<string, int|string>
     */
    private function delivery(): array
    {
        $attempts = max(0, (int) $this->option('count'));
        $row = $this->makeDelivery(attempts: $attempts, status: 'pending');

        return [
            'delivery_id' => Identity::stringify($row->getKey()),
            'attempts' => $attempts,
            'backoff_seconds' => Config::webhookBackoff(max(1, $attempts + 1)),
            'max_attempts' => Config::webhookMaxAttempts(),
            'disable_after' => Config::webhookDisableAfterFailures(),
        ];
    }

    private function makeDelivery(int $attempts, string $status): WebhookDelivery
    {
        $endpointId = $this->ensureProbeEndpoint();
        $eventId = $this->prefix().'delivery-'.Str::lower((string) Str::ulid());
        $row = new WebhookDelivery;
        $row->forceFill([
            'endpoint_id' => $endpointId,
            'event' => 'load.queue',
            'payload' => ['event_id' => $eventId],
            'status' => $status,
            'attempts' => $attempts,
            'next_retry_at' => now(),
        ])->save();

        return $row;
    }

    private function findDelivery(): WebhookDelivery
    {
        $id = (string) $this->option('delivery-id');
        $row = (new WebhookDelivery)->newQuery()->find($id);

        if (!$row instanceof WebhookDelivery) {
            throw new RuntimeException('Delivery not found.');
        }

        return $row;
    }

    private function ensureProbeEndpoint(): int|string
    {
        $endpoint = new WebhookEndpoint;
        // A control must not inherit another cell's response profile or failures.
        $existing = $endpoint->newQuery()->where('url', $this->url(1))->first();

        if ($existing instanceof WebhookEndpoint) {
            return $existing->getKey();
        }

        $row = new WebhookEndpoint;
        $row->forceFill([
            'url' => $this->url(1),
            'events' => ['load.queue'],
            'signing_secret' => 'p6-stand-'.$this->runId(),
            'is_active' => true,
            'failure_count' => 0,
        ])->save();

        return $row->getKey();
    }

    private function url(int $index): string
    {
        $profile = (string) $this->option('profile');
        $profile = $profile !== '' ? $profile : '2xx';
        $query = http_build_query([
            'run_id' => $this->runId(),
            'cell_id' => (string) $this->option('cell-id'),
            'slot' => (string) $index,
        ]);
        $delay = (string) $this->option('delay-ms');

        if ($delay !== '') {
            $query .= '&delay_ms='.rawurlencode($delay);
        }

        return 'http://webhook-sink:8090/'.$profile.'?'.$query;
    }

    private function runId(): string
    {
        $runId = (string) $this->option('run-id');

        if ($runId === '' || preg_match('/^[A-Za-z0-9._:-]+$/', $runId) !== 1) {
            throw new RuntimeException('A safe --run-id is required.');
        }

        return $runId;
    }

    private function prefix(): string
    {
        return 'p6:'.$this->runId().':';
    }
}
