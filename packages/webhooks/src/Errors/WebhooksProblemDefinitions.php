<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Errors;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 problem definitions for all `axiomasoft/vaulter-webhooks` error codes.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class WebhooksProblemDefinitions implements ProblemDefinitionProvider
{
    private const string URN_PREFIX = 'urn:packman:problem:';

    /** @return iterable<ProblemDefinition> */
    #[Override]
    public function definitions(): iterable
    {
        yield new ProblemDefinition(code: WebhooksErrorCode::WebhookResponseTooLarge, type: self::URN_PREFIX.WebhooksErrorCode::WebhookResponseTooLarge->value, defaultStatus: 500, title: 'Webhook response too large', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: WebhooksErrorCode::WebhookEndpointNotFound, type: self::URN_PREFIX.WebhooksErrorCode::WebhookEndpointNotFound->value, defaultStatus: 404, title: 'Webhook endpoint not found', retryable: false, category: ErrorCategory::NotFound);
        yield new ProblemDefinition(code: WebhooksErrorCode::InvalidWebhookSignature, type: self::URN_PREFIX.WebhooksErrorCode::InvalidWebhookSignature->value, defaultStatus: 400, title: 'Invalid webhook signature', retryable: false, category: ErrorCategory::Validation);
        yield new ProblemDefinition(code: WebhooksErrorCode::BlockedOutboundUrl, type: self::URN_PREFIX.WebhooksErrorCode::BlockedOutboundUrl->value, defaultStatus: 500, title: 'Blocked outbound url', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: WebhooksErrorCode::WebhookDeliveryNotFound, type: self::URN_PREFIX.WebhooksErrorCode::WebhookDeliveryNotFound->value, defaultStatus: 404, title: 'Webhook delivery not found', retryable: false, category: ErrorCategory::NotFound);
        yield new ProblemDefinition(code: WebhooksErrorCode::WebhookDeliveryNotRedeliverable, type: self::URN_PREFIX.WebhooksErrorCode::WebhookDeliveryNotRedeliverable->value, defaultStatus: 409, title: 'Webhook delivery not redeliverable', retryable: false, category: ErrorCategory::Conflict);
    }
}
