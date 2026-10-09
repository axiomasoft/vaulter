<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Webhooks;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A received webhook failed Standard Webhooks verification (StandardWebhookVerifier).
 * Raised on the receiving side: a host that lets it reach the exception handler answers
 * 400 without detail about which check failed.
 */
final class InvalidWebhookSignatureException extends VaulterException implements ProblemException
{
    public static function missingHeaders(): self
    {
        return new self('[Vaulter] Webhook verification failed: webhook-id, webhook-timestamp or webhook-signature header is missing. '
            .'Fix: pass the request headers unchanged. Docs: '.self::docs('webhook-signature'));
    }

    public static function invalidTimestamp(): self
    {
        return new self('[Vaulter] Webhook verification failed: webhook-timestamp is not a unix timestamp. '
            .'Fix: pass the request headers unchanged. Docs: '.self::docs('webhook-signature'));
    }

    public static function timestampOutsideTolerance(): self
    {
        return new self('[Vaulter] Webhook verification failed: webhook-timestamp is outside the accepted tolerance. '
            .'Why: a stale or future timestamp is how a replayed request looks. Docs: '.self::docs('webhook-signature'));
    }

    public static function noMatchingSignature(): self
    {
        return new self('[Vaulter] Webhook verification failed: no signature matches the body and the configured secrets. '
            .'Fix: verify the raw request body (not re-encoded JSON) against the endpoint secret. Docs: '.self::docs('webhook-signature'));
    }

    public function problemCode(): string
    {
        return 'invalid_webhook_signature';
    }

    public function httpStatus(): int
    {
        return 400;
    }
}
