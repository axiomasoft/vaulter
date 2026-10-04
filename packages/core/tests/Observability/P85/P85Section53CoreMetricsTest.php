<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Enums\Events\EventType;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Load\Listeners\RecordCommandPeakMemory;
use Vaulter\Load\Listeners\RecordFailedJob;
use Vaulter\Load\Observability\LoadStateMetrics;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\OutboxRecorder;
use Vaulter\Webhooks\OutboxRelay;
use Vaulter\Webhooks\WebhookRetryDispatcher;
use Vaulter\Webhooks\WebhookSigner;

beforeEach(function (): void {
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);
    $this->app->forgetInstance(MetricsRecorder::class);
});

it('records vaulter_outbox_backlog from pending outbox rows', function (): void {
    WebhookTestHelpers::outbox(['status' => 'pending']);
    WebhookTestHelpers::outbox(['status' => 'dispatched']);

    app(LoadStateMetrics::class)->observeOutboxBacklog();

    app(MetricsRecorder::class)->assertGauge(name: 'vaulter_outbox_backlog');
});

it('records vaulter_outbox_stage_duration_seconds when staging a new row', function (): void {
    app(OutboxRecorder::class)->stage(
        event: EventType::GrantCreated,
        eventId: (string) Str::ulid(),
        panel: 'default',
        topic: 'node-1',
        payload: ['grant_id' => 'g-1'],
        actorId: 1,
    );

    app(MetricsRecorder::class)->assertHistogram(name: 'vaulter_outbox_stage_duration_seconds');
});

it('records outbox relay metrics for a pending row', function (): void {
    Queue::fake();

    WebhookTestHelpers::endpoint();
    WebhookTestHelpers::endpoint(['events' => ['grant.created'], 'url' => 'https://hooks.test/other']);
    WebhookTestHelpers::outbox();

    app(OutboxRelay::class)->process();

    $recorder = app(MetricsRecorder::class);
    $recorder->assertHistogram(name: 'vaulter_outbox_relay_duration_seconds');
    $recorder->assertCounter(name: 'vaulter_outbox_relayed_total');
    $recorder->assertCounter(name: 'vaulter_outbox_endpoints_scanned_total');

    $scanned = collect($recorder->emissions())
        ->where('name', 'vaulter_outbox_endpoints_scanned_total')
        ->sum('value');

    expect($scanned)->toBe(1);
});

it('records webhook retry tick and lockset metrics', function (): void {
    Bus::fake();

    WebhookTestHelpers::delivery(
        WebhookTestHelpers::endpoint(),
        ['status' => 'failed', 'next_retry_at' => now()->subSecond()],
    );

    app(WebhookRetryDispatcher::class)->redispatchDue();

    $recorder = app(MetricsRecorder::class);
    $recorder->assertHistogram(name: 'vaulter_webhook_retry_tick_seconds');
    $recorder->assertHistogram(name: 'vaulter_webhook_retry_lockset_rows');
});

it('records webhook delivery metrics on successful deliver', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);

    $delivery = WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint());

    (new DeliverWebhook(deliveryId: (string) $delivery->getKey()))
        ->handle(app(WebhookSigner::class), app(OutboundUrlGuard::class));

    $recorder = app(MetricsRecorder::class);
    $recorder->assertHistogram(name: 'vaulter_webhook_queue_wait_seconds', labels: ['outcome' => 'success']);
    $recorder->assertHistogram(name: 'vaulter_webhook_delivery_duration_seconds', labels: ['outcome' => 'success']);
    $recorder->assertCounter(name: 'vaulter_webhook_delivered_total', labels: ['outcome' => 'success']);
});

it('records vaulter_failed_jobs_total on job failed event', function (): void {
    $exception = new RuntimeException('job failed hard');
    $job = Mockery::mock(Job::class);

    app(RecordFailedJob::class)->handle(new JobFailed(connectionName: 'redis', job: $job, exception: $exception));

    app(MetricsRecorder::class)->assertCounter(name: 'vaulter_failed_jobs_total', labels: ['outcome' => 'failure']);
});

it('records vaulter_command_peak_memory_bytes for vaulter artisan commands', function (): void {
    $commands = [
        'vaulter:sweep-uploads',
        'vaulter:gc-blobs',
        'vaulter:reconcile-blobs',
        'vaulter:purge-trash',
        'vaulter:prune-webhooks',
        'vaulter:retry-webhooks',
        'vaulter:relay-outbox',
    ];

    foreach ($commands as $command) {
        app(RecordCommandPeakMemory::class)->handle(new CommandFinished(
            command: $command,
            input: new ArrayInput([]),
            output: new BufferedOutput,
            exitCode: 0,
        ));

        app(MetricsRecorder::class)->assertGauge(
            name: 'vaulter_command_peak_memory_bytes',
            labels: ['command' => $command],
        );
    }
});

it('records prune webhook metrics when rows are reclaimed', function (): void {
    config()->set('vaulter.features.reliability', true);
    config()->set('vaulter.webhooks.retention_days', 0);

    $threshold = now()->subDay();
    WebhookTestHelpers::outbox([
        'status' => 'dispatched',
        'dispatched_at' => $threshold,
    ]);

    Artisan::call('vaulter:prune-webhooks');

    $recorder = app(MetricsRecorder::class);
    $recorder->assertHistogram(name: 'vaulter_prune_duration_seconds');
    $recorder->assertCounter(name: 'vaulter_prune_rows_total');
});

it('exposes vaulter_pg_lock_wait_seconds from the harness collector script', function (): void {
    $script = dirname(__DIR__, 5).'/load/scripts/collect-postgres-lock-waits.sh';

    expect(is_file($script))->toBeTrue()
        ->and(file_get_contents($script))->toContain('vaulter_pg_lock_wait_seconds');
});
