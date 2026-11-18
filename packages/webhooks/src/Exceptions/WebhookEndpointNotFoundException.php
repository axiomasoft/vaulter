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

#[Internal]
final class WebhookEndpointNotFoundException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function forId(int|string $id): self
    {
        return new self(
            "[Vaulter] Webhook endpoint [{$id}] was not found. "
            .'Why: no endpoint has this id; it may have been deleted. '
            .'Fix: list the endpoints and use a current id. '
            .'Docs: '.self::docs('webhook-endpoint-not-found'),
        );
    }

    public function problemCode(): string
    {
        return 'webhook_endpoint_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: WebhooksErrorCode::WebhookEndpointNotFound, category: ErrorCategory::NotFound);
    }
}
