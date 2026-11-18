<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Exceptions;

use Override;
use Packman\Attributes\Internal;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Webhooks\Errors\WebhooksErrorCode;

/** A manual re-delivery was asked for a delivery that cannot be sent again right now. */
#[Internal]
final class WebhookDeliveryNotRedeliverableException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function notTerminal(int|string $deliveryId, string $status): self
    {
        return new self(
            "[Vaulter] Webhook delivery [{$deliveryId}] cannot be redelivered: it is [{$status}]. "
            .'Why: only a delivered or dead delivery can be sent again; a pending or failed one is still being retried. '
            .'Fix: wait for the retries to finish, or redeliver once it is dead. '
            .'Docs: '.self::docs('webhook-delivery-not-redeliverable'),
        );
    }

    public static function endpointInactive(int|string $deliveryId): self
    {
        return new self(
            "[Vaulter] Webhook delivery [{$deliveryId}] cannot be redelivered: its endpoint is inactive. "
            .'Why: an inactive endpoint receives nothing, and the delivery would be killed again at once. '
            .'Fix: re-enable the endpoint first. '
            .'Docs: '.self::docs('webhook-delivery-not-redeliverable'),
        );
    }

    public function problemCode(): string
    {
        return 'webhook_delivery_not_redeliverable';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: WebhooksErrorCode::WebhookDeliveryNotRedeliverable, category: ErrorCategory::Conflict);
    }
}
