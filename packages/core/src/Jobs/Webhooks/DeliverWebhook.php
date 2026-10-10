<?php

declare(strict_types=1);

namespace Vaulter\Jobs\Webhooks;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Configuration\WebhooksConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Observability\TracingAttributes;
use Vaulter\Observability\TracingSpanNames;
use Vaulter\ValueObjects\Webhooks\PinnedOutboundEndpoint;
use Vaulter\Webhooks\Events\WebhookEndpointDisabled;
use Vaulter\Webhooks\ResponseSizeLimit;
use Vaulter\Webhooks\WebhookSigner;

/**
 * Delivers one WebhookDelivery to its endpoint over a Standard Webhooks signed HTTP POST
 * (master plan §2.11 / D14 / M3b, PLAN2.D60). The delivery is QUEUED, so it never blocks
 * the originating request.
 *
 * At-least-once with a retry schedule and a dead-letter terminal state:
 *   - 2xx        → delivery 'delivered'; the endpoint's failure run is cleared.
 *   - anything   → attempts++, last_error recorded. While the schedule has entries the
 *     else           delivery is parked 'failed' with next_retry_at (schedule delay with
 *                    jitter) and the job returns; the relay tick (vaulter:relay-outbox /
 *                    vaulter:retry-webhooks) re-dispatches it once due. Past the last
 *                    entry it is parked 'dead' (DLQ).
 *   Redirects, 3xx, 4xx and 5xx all count as failures; only 2xx is success.
 *
 * Every failed attempt starts or extends the endpoint's failing run (failing_since).
 * Once the run is disable_after_days old the endpoint is switched off and its open
 * deliveries die, in the same transaction that settles the attempt; a success ends the run.
 *
 * Retry has a SINGLE owner: the relay's due-retry sweep, NOT the queue. The job never
 * self-releases, so a running worker and a scheduled relay can't both re-arm the same
 * failed row (no double-POST, no attempts race).
 *
 * A database lease excludes overlapping jobs for the same delivery. HTTP runs
 * outside a transaction; completion is fenced by the lease token. A lost worker's
 * lease expires so the retry sweep can recover it. Remote acceptance followed by
 * a worker crash still permits a repeated POST (at-least-once).
 */
final class DeliverWebhook implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private VaulterConfig $vaulterConfig;

    private WebhooksConfig $webhooksConfig;

    public function __construct(
        private readonly string $deliveryId,
    ) {}

    public function handle(WebhookSigner $signer, OutboundUrlGuard $urlGuard, VaulterConfig $vaulterConfig, WebhooksConfig $webhooksConfig, ?VaulterDatabase $database = null): void
    {
        // The container always injects the database; a direct call without one runs unscoped.
        $database === null ? $this->deliver($signer, $urlGuard, $vaulterConfig, $webhooksConfig) : $database->write(fn () => $this->deliver($signer, $urlGuard, $vaulterConfig, $webhooksConfig));
    }

    private function deliver(WebhookSigner $signer, OutboundUrlGuard $urlGuard, VaulterConfig $vaulterConfig, WebhooksConfig $webhooksConfig): void
    {
        $this->vaulterConfig = $vaulterConfig;
        $this->webhooksConfig = $webhooksConfig;
        $metrics = app(MetricsRecorder::class);
        $tracer = app(TraceRecorder::class);

        $tracer->span(
            name: TracingSpanNames::WEBHOOK_DELIVERY,
            operation: function () use ($metrics, $signer, $urlGuard): void {
                $delivery = $this->resolveDelivery();

                if ($delivery === null || $this->isTerminal(delivery: $delivery)) {
                    return;
                }

                $endpoint = $delivery->endpoint;

                if (!$endpoint instanceof WebhookEndpoint) {
                    return;
                }

                // Nothing is sent to an endpoint that is off — a redelivery or a late job racing the
                // switch-off lands here. The row dies instead of waiting for a sweep that would
                // keep re-queueing it.
                if (!$endpoint->is_active) {
                    $delivery->newQuery()->whereKey($delivery->getKey())->whereIn('status', ['pending', 'failed'])->toBase()->update([
                        'status' => 'dead', 'next_retry_at' => null, 'claim_token' => null, 'claim_until' => null,
                        'last_error' => 'endpoint_disabled', 'updated_at' => now(),
                    ]);

                    return;
                }

                if (!$this->claim(delivery: $delivery)) {
                    return;
                }

                $started = hrtime(true);
                $outcome = 'failure';

                try {
                    $response = $this->post(signer: $signer, urlGuard: $urlGuard, delivery: $delivery, endpoint: $endpoint);
                } catch (Throwable $throwable) {
                    $outcome = $throwable instanceof ConnectionException ? 'timeout' : 'failure';
                    if ($this->settle(delivery: $delivery, endpoint: $endpoint, operation: fn (WebhookEndpoint $locked) => $this->recordFailure(delivery: $delivery, endpoint: $locked, error: $throwable->getMessage()))) {
                        $this->recordWebhookMetrics(metrics: $metrics, started: $started, delivery: $delivery, outcome: $outcome);
                    }

                    return;
                }

                if ($response->successful()) {
                    if ($this->settle(delivery: $delivery, endpoint: $endpoint, operation: fn (WebhookEndpoint $locked) => $this->recordSuccess(delivery: $delivery, endpoint: $locked))) {
                        $this->recordWebhookMetrics(metrics: $metrics, started: $started, delivery: $delivery, outcome: 'success');
                    }

                    return;
                }

                if (!$this->settle(delivery: $delivery, endpoint: $endpoint, operation: fn (WebhookEndpoint $locked) => $this->recordFailure(
                    delivery: $delivery, endpoint: $locked, error: 'HTTP '.$response->status(),
                ))) {
                    return;
                }

                $delivery->refresh();
                $outcome = $delivery->status === 'dead' ? 'failure' : 'retry';
                $this->recordWebhookMetrics(metrics: $metrics, started: $started, delivery: $delivery, outcome: $outcome);
            },
            attributes: TracingAttributes::forTarget(targetId: 'webhook.pipeline', layer: 'job'),
        );
    }

    private function recordWebhookMetrics(
        MetricsRecorder $metrics,
        int $started,
        WebhookDelivery $delivery,
        string $outcome,
    ): void {
        $duration = (hrtime(true) - $started) / 1_000_000_000;
        $enqueuedAt = $delivery->created_at?->getTimestamp();

        if ($enqueuedAt !== null) {
            $metrics->histogram(
                name: 'vaulter_webhook_queue_wait_seconds',
                value: max(0, microtime(true) - (float) $enqueuedAt),
                labels: ['outcome' => $outcome],
            );
        }

        $metrics->histogram(
            name: 'vaulter_webhook_delivery_duration_seconds',
            value: $duration,
            labels: ['outcome' => $outcome],
        );

        if (in_array($outcome, ['success', 'failure'], true)) {
            $metrics->counter(
                name: 'vaulter_webhook_delivered_total',
                labels: ['outcome' => $outcome],
            );
        }
    }

    /**
     * POST the signed payload to the endpoint with the Standard Webhooks headers. The
     * signature covers "{webhook-id}.{webhook-timestamp}.{body}" over the exact bytes
     * sent, so the receiver verifies byte-for-byte, deduplicates by webhook-id (the event
     * id, identical on every retry) and rejects a replay by timestamp. While a rotation
     * overlap lasts the header carries two signatures, the current secret's first.
     *
     * SSRF-hardened (§2.11 / N2 / F-P1.7-01): the guard resolves + range-checks the
     * URL on the delivery path and hands back the vetted IP, which is PINNED onto the
     * connection (CURLOPT_RESOLVE) so DNS cannot rebind to an internal address between
     * the check and the dial. The transport is locked to HTTP(S) and the response body
     * is capped (F-P1.7-06). A blocked URL throws and is recorded as a failed attempt —
     * nothing leaves the process.
     *
     * REDIRECTS ARE NEVER FOLLOWED (VLT-REMED P5.2 / R-05 / D30). The pin covers only the
     * ORIGINAL host:port; a redirect hop is resolved again, with no guard and no pin, so a
     * receiver answering `302 Location: http://169.254.169.254/…` walks the request straight
     * onto the metadata service and reopens the Blocker in full. This used to be a config
     * key (`webhooks.follow_redirects`) — a boolean that switched a security barrier off is
     * not flexibility, it is a mine, so the key is GONE and the behaviour is unconditional.
     * A webhook receiver is expected to answer for itself, not to bounce us elsewhere.
     */
    private function post(WebhookSigner $signer, OutboundUrlGuard $urlGuard, WebhookDelivery $delivery, WebhookEndpoint $endpoint): Response
    {
        $pinned = $urlGuard->pin($endpoint->url);

        $body = (string) json_encode($delivery->payload);
        $timestamp = now()->getTimestamp();
        $payloadId = $delivery->payload['id'] ?? null;
        $webhookId = is_string($payloadId) && $payloadId !== '' ? $payloadId : (string) $delivery->event_id;

        $request = Http::timeout($this->webhooksConfig->timeout())
            ->connectTimeout($this->webhooksConfig->connectTimeout())
            // Unconditional (D30) — see the docblock: a redirect hop escapes the pin.
            ->withoutRedirecting()
            ->withOptions($this->transportOptions(pinned: $pinned))
            ->withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'Vaulter-Webhooks/1',
                'webhook-id' => $webhookId,
                'webhook-timestamp' => (string) $timestamp,
                'webhook-signature' => $signer->signatureHeader(
                    webhookId: $webhookId,
                    timestamp: $timestamp,
                    body: $body,
                    secrets: $endpoint->activeSigningSecrets(),
                ),
            ])
            ->withBody($body, 'application/json');

        return $request->post($endpoint->url);
    }

    /**
     * The Guzzle transport options that harden a delivery (§2.11 / N2): pin the vetted
     * IP onto the connection, lock the protocol to HTTP(S), forbid the transport itself
     * from following a redirect (the second barrier under `withoutRedirecting()` — R-05:
     * a cURL handle that never follows Location cannot leave the pinned host even if the
     * HTTP layer is reconfigured), and bound the response body — an oversized declared
     * Content-Length is refused from the on_headers hook, and the buffering sink spills to
     * a temp file so an UNDECLARED oversize body never lands whole in RAM.
     *
     * @return array<string, mixed>
     */
    private function transportOptions(PinnedOutboundEndpoint $pinned): array
    {
        $options = [
            'curl' => [
                CURLOPT_RESOLVE => [$pinned->curlResolveEntry()],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
            ],
        ];

        $maxResponseBytes = $this->webhooksConfig->maxResponseBytes();

        if ($maxResponseBytes > 0) {
            $sink = fopen('php://temp/maxmemory:'.$maxResponseBytes, 'w+b');

            if ($sink !== false) {
                $options['sink'] = $sink;
            }

            $options['on_headers'] = static function (ResponseInterface $response) use ($maxResponseBytes): void {
                $header = $response->getHeaderLine('Content-Length');

                ResponseSizeLimit::assertContentLengthWithinLimit(
                    contentLength: $header === '' ? null : $header,
                    maxBytes: $maxResponseBytes,
                );
            };
        }

        return $options;
    }

    private function recordSuccess(WebhookDelivery $delivery, WebhookEndpoint $endpoint): void
    {
        $delivery->forceFill([
            'status' => 'delivered',
            'attempts' => $delivery->attempts + 1,
            'next_retry_at' => null,
            'last_error' => null,
        ])->save();

        // A success ends the failing run: the auto-disable clock restarts at the next failure.
        $endpoint->forceFill([
            'last_triggered_at' => now(),
            'failure_count' => 0,
            'failing_since' => null,
        ])->save();
    }

    /**
     * Account a failed attempt: bump attempts, record the error, then either arm the
     * next retry (durable next_retry_at from the schedule — the relay sweep re-dispatches
     * it once due, the job does NOT self-release) or, once the schedule is spent, park
     * the delivery dead. Either way the endpoint's failing run grows, and a run that
     * reached disable_after_days switches the endpoint off.
     */
    private function recordFailure(WebhookDelivery $delivery, WebhookEndpoint $endpoint, string $error): void
    {
        $attempts = $delivery->attempts + 1;
        $schedule = $this->webhooksConfig->retrySchedule();

        if ($attempts <= count($schedule)) {
            $delivery->forceFill([
                'status' => 'failed',
                'attempts' => $attempts,
                'next_retry_at' => now()->addSeconds($this->jitteredDelay(seconds: $schedule[$attempts - 1])),
                'last_error' => $error,
            ])->save();
        } else {
            $delivery->forceFill([
                'status' => 'dead',
                'attempts' => $attempts,
                'next_retry_at' => null,
                'last_error' => $error,
            ])->save();
        }

        $this->extendFailingRun(delivery: $delivery, endpoint: $endpoint);
    }

    /**
     * Start or extend the endpoint's unbroken run of failures and switch the endpoint
     * off when the run is old enough. Runs under the endpoint row lock (see settle()),
     * so concurrent settlements disable it once.
     */
    private function extendFailingRun(WebhookDelivery $delivery, WebhookEndpoint $endpoint): void
    {
        if (!$endpoint->is_active) {
            return;
        }

        $endpoint->forceFill([
            'failing_since' => $endpoint->failing_since ?? now(),
            'failure_count' => $endpoint->failure_count + 1,
        ])->save();

        $days = $this->webhooksConfig->disableAfterDays();
        $failingSince = $endpoint->failing_since;

        if ($days === 0 || $failingSince === null || $failingSince->greaterThan(now()->subDays($days))) {
            return;
        }

        $endpoint->forceFill(['is_active' => false, 'disabled_at' => now(), 'disabled_reason' => 'failing'])->save();

        // Same outcome as WebhookDeliveryStoreRepository::killOpenForEndpoint(): nothing may be
        // sent afterwards, and dropping the claims fences the jobs still in flight. Written
        // here, not injected, because a queued job's handle() takes no further collaborators.
        $delivery->newQuery()
            ->where('endpoint_id', $endpoint->getKey())
            ->whereIn('status', ['pending', 'failed'])
            ->toBase()
            ->update([
                'status' => 'dead',
                'next_retry_at' => null,
                'claim_token' => null,
                'claim_until' => null,
                'last_error' => 'endpoint_disabled',
                'updated_at' => now(),
            ]);

        // Hosts hear about it only once the disabling is durable.
        $endpoint->getConnection()->afterCommit(static function () use ($endpoint, $failingSince): void {
            Event::dispatch(new WebhookEndpointDisabled(endpointId: $endpoint->getKey(), reason: 'failing', failingSince: $failingSince));
        });
    }

    private function jitteredDelay(int $seconds): int
    {
        $jitter = $this->webhooksConfig->retryJitter();

        if ($jitter === 0.0 || $seconds === 0) {
            return $seconds;
        }

        // +/- jitter around the scheduled delay, so a burst of failures does not retry in lockstep.
        return max(0, (int) round($seconds * (1 + $jitter * (random_int(-1000, 1000) / 1000))));
    }

    private function isTerminal(WebhookDelivery $delivery): bool
    {
        return in_array($delivery->status, ['delivered', 'dead'], true);
    }

    private function claim(WebhookDelivery $delivery): bool
    {
        $token = bin2hex(random_bytes(16));
        // Leave room for DNS/transport setup and settlement beyond the HTTP limit.
        $until = now()->addSeconds(max($this->webhooksConfig->retryClaimSeconds(), $this->webhooksConfig->timeout() + 30));

        $claimed = $delivery->newQuery()
            ->whereKey($delivery->getKey())
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempts', $delivery->attempts)
            ->where(static fn (Builder $query): Builder => $query->whereNull('claim_until')->orWhere('claim_until', '<=', now()))
            ->update(['claim_token' => $token, 'claim_until' => $until]);

        if ($claimed !== 1) {
            return false;
        }

        $delivery->forceFill(['claim_token' => $token, 'claim_until' => $until]);

        return true;
    }

    /**
     * @param Closure(WebhookEndpoint): void $operation receives the endpoint row as locked inside the transaction
     */
    private function settle(WebhookDelivery $delivery, WebhookEndpoint $endpoint, Closure $operation): bool
    {
        return $delivery->getConnection()->transaction(function () use ($delivery, $endpoint, $operation): bool {
            // The endpoint is locked BEFORE the delivery is touched: auto-disable rewrites
            // every open delivery of the endpoint, so any other order could deadlock two
            // settlements. Serialising here also makes the disable decision see the failure
            // count and failing_since the previous settlement committed.
            $locked = $endpoint->newQuery()->whereKey($endpoint->getKey())->lockForUpdate()->first();

            if (!$locked instanceof WebhookEndpoint) {
                return false;
            }

            // An atomic conditional write also fences on SQLite, where FOR UPDATE
            // is unavailable. A superseded worker cannot overwrite its successor — and
            // a delivery the disabling just killed has lost its claim.
            $owned = $delivery->newQuery()->whereKey($delivery->getKey())
                ->where('claim_token', $delivery->claim_token)
                ->update(['claim_token' => null, 'claim_until' => null]);

            if ($owned !== 1) {
                return false;
            }

            $delivery->forceFill(['claim_token' => null, 'claim_until' => null]);
            $operation($locked);

            return true;
        });
    }

    private function resolveDelivery(): ?WebhookDelivery
    {
        /** @var class-string<WebhookDelivery> $deliveryClass */
        $deliveryClass = $this->vaulterConfig->model('webhook_delivery');

        return $deliveryClass::query()->find($this->deliveryId);
    }
}
