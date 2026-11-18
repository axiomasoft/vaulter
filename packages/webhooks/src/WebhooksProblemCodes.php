<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Packman\Attributes\Internal;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Http\Problems\ProblemSpec;
use Vaulter\Http\Problems\ReceiptPolicy;
use Vaulter\Webhooks\Exceptions\InvalidWebhookSignatureException;
use Vaulter\Webhooks\Exceptions\WebhookDeliveryNotFoundException;
use Vaulter\Webhooks\Exceptions\WebhookDeliveryNotRedeliverableException;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;

/**
 * The problem codes of the webhooks module: declared by its manifest, so they are in the catalogue only
 * while the opt-in module is active. A stable public contract, so a code is added here, in
 * lang/{en,ru}/problems.php and in the reference docs together.
 */
#[Internal]
final class WebhooksProblemCodes implements ProblemCodeSource
{
    public function translationNamespace(): string
    {
        return 'vaulter-webhooks';
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
