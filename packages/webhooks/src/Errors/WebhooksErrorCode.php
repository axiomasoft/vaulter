<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Errors;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Canonical Packman error codes owned by `axiomasoft/vaulter-webhooks`.
 */
#[Api(since: '1.0')]
enum WebhooksErrorCode: string implements ErrorCode
{
    case WebhookResponseTooLarge = 'webhooks.webhook_response_too_large';
    case WebhookEndpointNotFound = 'webhooks.webhook_endpoint_not_found';
    case InvalidWebhookSignature = 'webhooks.invalid_webhook_signature';
    case BlockedOutboundUrl = 'webhooks.blocked_outbound_url';
    case WebhookDeliveryNotFound = 'webhooks.webhook_delivery_not_found';
    case WebhookDeliveryNotRedeliverable = 'webhooks.webhook_delivery_not_redeliverable';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}
