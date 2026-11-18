<?php

declare(strict_types=1);

use Vaulter\Exceptions\VaulterException;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Testing\Support\ProblemProbeRoutes;
use Vaulter\Webhooks\Exceptions\BlockedOutboundUrlException;
use Vaulter\Webhooks\Exceptions\InvalidWebhookSignatureException;
use Vaulter\Webhooks\Exceptions\WebhookDeliveryNotFoundException;
use Vaulter\Webhooks\Exceptions\WebhookDeliveryNotRedeliverableException;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;

/**
 * The domain exceptions of the webhooks package through the versioned surface (the blocked-URL case moved here from the
 * documents suite, which no longer installs the package): problem+json with the stable code and the catalogue status.
 */
it('renders each webhook exception with its stable code and HTTP status', function (
    VaulterException $exception,
    string $code,
    int $status,
    array $extras = [],
): void {
    expect(method_exists($exception, 'render'))->toBeFalse();

    ProblemProbeRoutes::register(['case' => $exception]);
    $problem = $this->getJson(ProblemProbeRoutes::path('case'))
        ->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', $code)
        ->assertJsonPath('status', $status);

    expect($problem->json())->not->toHaveKey('message')
        ->and($problem->getContent())->not->toContain('[Vaulter]')
        ->and(app(ProblemCatalog::class)->all())->toHaveKey($code)
        ->and($problem->json('meta'))->toBe(array_diff_key($extras, ['errors' => true]));
})->with([
    'blocked outbound URL' => [$blockedUrl = BlockedOutboundUrlException::scheme('file'), 'validation_failed', 422, ['errors' => ['url' => [$blockedUrl->getMessage()]]]],
    'blocked outbound address' => [$blockedAddress = BlockedOutboundUrlException::blockedAddress('evil.test', '127.0.0.1'), 'validation_failed', 422, ['errors' => ['url' => [$blockedAddress->getMessage()]]]],
    'endpoint missing' => [WebhookEndpointNotFoundException::forId(7), 'webhook_endpoint_not_found', 404],
    'delivery missing' => [WebhookDeliveryNotFoundException::forId(7), 'webhook_delivery_not_found', 404],
    'delivery still retried' => [WebhookDeliveryNotRedeliverableException::notTerminal(7, 'failed'), 'webhook_delivery_not_redeliverable', 409],
    'delivery endpoint inactive' => [WebhookDeliveryNotRedeliverableException::endpointInactive(7), 'webhook_delivery_not_redeliverable', 409],
    'signature headers missing' => [InvalidWebhookSignatureException::missingHeaders(), 'invalid_webhook_signature', 400],
    'signature mismatch' => [InvalidWebhookSignatureException::noMatchingSignature(), 'invalid_webhook_signature', 400],
]);
