<?php

declare(strict_types=1);

namespace Vaulter\Jobs\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Support\Config;
use Vaulter\Webhooks\WebhookSigner;

/**
 * Delivers one WebhookDelivery to its endpoint over signed HTTP POST (master plan
 * §2.11 / D14 / M3b), ported from chatom — the reliable webhook channel. The delivery
 * is QUEUED, so it never blocks the originating request.
 *
 * At-least-once with exponential backoff and a dead-letter terminal state:
 *   - 2xx        → delivery 'delivered'; endpoint failure_count reset, touched.
 *   - non-2xx /
 *     transport  → attempts++, last_error recorded. If attempts are left, the delivery
 *     exception      is parked 'failed' with next_retry_at on the configured backoff
 *                    and the job returns; the relay tick (vaulter:relay-outbox /
 *                    vaulter:retry-webhooks) re-dispatches it once due. Otherwise it is
 *                    parked 'dead' (DLQ) and the endpoint's failure_count is bumped —
 *                    once it reaches disable_after_failures the endpoint auto-disables.
 *
 * Retry has a SINGLE owner: the relay's due-retry sweep, NOT the queue. The job never
 * self-releases, so a running worker and a scheduled relay can't both re-arm the same
 * failed row (no double-POST, no attempts race).
 *
 * Idempotent on the delivery row: a job for an already-terminal delivery
 * (delivered|dead) is a no-op, so a re-dispatch never double-delivers.
 */
final class DeliverWebhook implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $deliveryId,
    ) {}

    public function handle(WebhookSigner $signer): void
    {
        $delivery = $this->resolveDelivery();

        if ($delivery === null || $this->isTerminal(delivery: $delivery)) {
            return;
        }

        $endpoint = $delivery->endpoint;

        if (!$endpoint instanceof WebhookEndpoint) {
            return;
        }

        try {
            $response = $this->post(signer: $signer, delivery: $delivery, endpoint: $endpoint);
        } catch (Throwable $exception) {
            $this->recordFailure(delivery: $delivery, endpoint: $endpoint, error: $exception->getMessage());

            return;
        }

        if ($response->successful()) {
            $this->recordSuccess(delivery: $delivery, endpoint: $endpoint);

            return;
        }

        $this->recordFailure(
            delivery: $delivery,
            endpoint: $endpoint,
            error: "HTTP {$response->status()}",
        );
    }

    /**
     * POST the signed payload to the endpoint. The signature is HMAC-SHA256 over
     * "{timestamp}.{body}" with the endpoint's signing_secret, so the receiver can
     * verify it byte-for-byte AND reject a replayed request whose timestamp is stale.
     */
    private function post(WebhookSigner $signer, WebhookDelivery $delivery, WebhookEndpoint $endpoint): Response
    {
        $body = (string) json_encode($delivery->payload);
        $timestamp = now()->getTimestamp();

        return Http::timeout(Config::webhookTimeout())
            ->withHeaders([
                'Content-Type' => 'application/json',
                Config::webhookEventHeader() => $delivery->event,
                Config::webhookTimestampHeader() => (string) $timestamp,
                Config::webhookSignatureHeader() => $signer->sign(
                    body: $body,
                    secret: $endpoint->signing_secret,
                    timestamp: $timestamp,
                ),
            ])
            ->withBody($body, 'application/json')
            ->post($endpoint->url);
    }

    private function recordSuccess(WebhookDelivery $delivery, WebhookEndpoint $endpoint): void
    {
        $delivery->forceFill([
            'status' => 'delivered',
            'attempts' => $delivery->attempts + 1,
            'next_retry_at' => null,
            'last_error' => null,
        ])->save();

        $endpoint->forceFill([
            'last_triggered_at' => now(),
            'failure_count' => 0,
        ])->save();
    }

    /**
     * Account a failed attempt: bump attempts, record the error, then either arm the
     * next retry (durable next_retry_at on backoff — the relay sweep re-dispatches it
     * once due, the job does NOT self-release) or, once the attempt budget is spent,
     * park the delivery dead and bump the endpoint's failure counter — auto-disabling
     * it past the configured threshold.
     */
    private function recordFailure(WebhookDelivery $delivery, WebhookEndpoint $endpoint, string $error): void
    {
        $attempts = $delivery->attempts + 1;

        if ($attempts < Config::webhookMaxAttempts()) {
            $backoff = Config::webhookBackoff(attempt: $attempts);

            $delivery->forceFill([
                'status' => 'failed',
                'attempts' => $attempts,
                'next_retry_at' => now()->addSeconds($backoff),
                'last_error' => $error,
            ])->save();

            return;
        }

        $delivery->forceFill([
            'status' => 'dead',
            'attempts' => $attempts,
            'next_retry_at' => null,
            'last_error' => $error,
        ])->save();

        $this->penalizeEndpoint(endpoint: $endpoint);
    }

    private function penalizeEndpoint(WebhookEndpoint $endpoint): void
    {
        $failures = $endpoint->failure_count + 1;

        $endpoint->forceFill([
            'failure_count' => $failures,
            'is_active' => $failures < Config::webhookDisableAfterFailures(),
        ])->save();
    }

    private function isTerminal(WebhookDelivery $delivery): bool
    {
        return in_array($delivery->status, ['delivered', 'dead'], true);
    }

    private function resolveDelivery(): ?WebhookDelivery
    {
        /** @var class-string<WebhookDelivery> $deliveryClass */
        $deliveryClass = Config::webhookDeliveryModel();

        return $deliveryClass::query()->find($this->deliveryId);
    }
}
