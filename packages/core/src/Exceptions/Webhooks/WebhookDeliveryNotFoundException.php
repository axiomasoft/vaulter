<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Webhooks;

use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Internal]
final class WebhookDeliveryNotFoundException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function forId(int|string $id): self
    {
        return new self(
            "[Vaulter] Webhook delivery [{$id}] was not found for this endpoint. "
            .'Why: the delivery does not exist, belongs to another endpoint, or was pruned after its retention period. '
            .'Fix: list the endpoint\'s deliveries and use a current id. '
            .'Docs: '.self::docs('webhook-delivery-not-found'),
        );
    }

    public function problemCode(): string
    {
        return 'webhook_delivery_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
