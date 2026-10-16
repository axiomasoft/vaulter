<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Webhooks\InvalidWebhookSignatureException;
use Vaulter\Exceptions\Webhooks\WebhookDeliveryNotFoundException;
use Vaulter\Exceptions\Webhooks\WebhookDeliveryNotRedeliverableException;
use Vaulter\Exceptions\Webhooks\WebhookEndpointNotFoundException;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Http\Problems\ProblemSpec;
use Vaulter\Http\Problems\ReceiptPolicy;

/**
 * The problem codes of the webhooks module (D39): declared by its logical manifest, so they are in the
 * catalogue only while the opt-in module is active. Their lines stay in the core lang files until P4 moves
 * the webhooks code. A stable public contract, so a code is added here, in lang/{en,ru}/problems.php and in
 * the reference docs together.
 */
#[Internal]
final class WebhooksProblemCodes implements ProblemCodeSource
{
    public function translationNamespace(): string
    {
        return 'vaulter';
    }

    public function codes(): array
    {
        return [
            'invalid_webhook_signature' => new ProblemSpec(400, InvalidWebhookSignatureException::class),
            'webhook_delivery_not_found' => new ProblemSpec(404, WebhookDeliveryNotFoundException::class),
            'webhook_delivery_not_redeliverable' => new ProblemSpec(409, WebhookDeliveryNotRedeliverableException::class, receipt: ReceiptPolicy::Cacheable),
            'webhook_endpoint_not_found' => new ProblemSpec(404, WebhookEndpointNotFoundException::class),
        ];
    }
}
