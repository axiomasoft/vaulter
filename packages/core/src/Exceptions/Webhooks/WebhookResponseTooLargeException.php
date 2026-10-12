<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Webhooks;

use Vaulter\Exceptions\VaulterException;

/**
 * A webhook receiver announced (or streamed) a response body larger than the
 * configured ceiling. Delivery needs only the status code, so an
 * oversized body is refused before it is buffered whole — a broken/hostile receiver
 * cannot OOM the delivery worker.
 */
final class WebhookResponseTooLargeException extends VaulterException
{
    public static function declared(int $bytes, int $limit): self
    {
        return new self(
            sprintf('[Vaulter] Webhook response refused: declared body of %d bytes exceeds the %d-byte limit. ', $bytes, $limit)
            .'Why: the delivery needs only the status code; an oversized body is a DoS vector. '
            .'Fix: have the receiver return a small (or empty) body, or raise vaulter.webhooks.delivery.max_response_bytes. '
            .'Docs: '.self::docs('webhook-response-too-large'),
        );
    }
}
