<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Observability\TracingAttributes;
use Vaulter\Observability\TracingSpanNames;
use Vaulter\Testing\FakeTraceRecorder;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\OutboxRelay;
use Vaulter\Webhooks\WebhookRetryDispatcher;

beforeEach(function (): void {
    config()->set('vaulter.observability.tracing.enabled', true);
    config()->set('vaulter.observability.tracing.recorder', FakeTraceRecorder::class);
    $this->app->forgetInstance(TraceRecorder::class);
});

it('records relay and retry spans for webhook.pipeline without duplicate layers', function (): void {
    $tracer = app(TraceRecorder::class);
    expect($tracer)->toBeInstanceOf(FakeTraceRecorder::class);

    $tracer->span(
        name: 'command.root',
        operation: function (): void {
            app(WebhookRetryDispatcher::class)->redispatchDue();
            app(OutboxRelay::class)->process(limit: 0);
        },
    );

    $tracer->assertSpan(
        name: TracingSpanNames::WEBHOOK_RETRY,
        attributes: TracingAttributes::forTarget(targetId: 'webhook.pipeline', layer: 'action'),
        parent: 'command.root',
    );
    $tracer->assertSpan(
        name: TracingSpanNames::WEBHOOK_RELAY,
        attributes: TracingAttributes::forTarget(targetId: 'webhook.pipeline', layer: 'action'),
        parent: 'command.root',
    );
});

it('records delivery job span for webhook.pipeline', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery(endpoint: $endpoint);

    Bus::dispatchSync(new DeliverWebhook(deliveryId: (string) $delivery->getKey()));

    $tracer = app(TraceRecorder::class);
    expect($tracer)->toBeInstanceOf(FakeTraceRecorder::class);
    $tracer->assertSpan(
        name: TracingSpanNames::WEBHOOK_DELIVERY,
        attributes: TracingAttributes::forTarget(targetId: 'webhook.pipeline', layer: 'job'),
    );
    expect($tracer->spans())->not->toBeEmpty();
});
