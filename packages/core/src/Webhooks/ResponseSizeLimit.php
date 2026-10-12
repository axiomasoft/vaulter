<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Vaulter\Exceptions\Webhooks\WebhookResponseTooLargeException;

/**
 * Caps the webhook receiver's response body. A delivery needs only
 * the status code, so a declared Content-Length above the limit is refused from the
 * Guzzle on_headers hook — before the body is read — and the buffering sink is bounded
 * so an UNDECLARED oversize body spills to a temp file instead of RAM.
 */
final class ResponseSizeLimit
{
    /**
     * Refuse an oversized declared body: throw when the Content-Length header exceeds
     * $maxBytes. An absent/non-numeric header is allowed (the bounded sink caps RAM for
     * an undeclared body). A non-positive $maxBytes disables the check.
     *
     * @throws WebhookResponseTooLargeException
     */
    public static function assertContentLengthWithinLimit(?string $contentLength, int $maxBytes): void
    {
        if ($maxBytes <= 0 || $contentLength === null || !is_numeric($contentLength)) {
            return;
        }

        $declared = (int) $contentLength;

        if ($declared > $maxBytes) {
            throw WebhookResponseTooLargeException::declared(bytes: $declared, limit: $maxBytes);
        }
    }
}
