<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

/**
 * Signs webhook delivery payloads with HMAC-SHA256 for the signature header the
 * relay sends (master plan §2.11 / D14). The signature is computed over a
 * "{timestamp}.{body}" string — binding the timestamp into the signed material is
 * what makes a captured request REPLAY-RESISTANT: a receiver rejects a request whose
 * timestamp is outside its tolerance, and the timestamp cannot be changed without
 * invalidating the signature.
 *
 * Thin, stateless, read-only helper: it computes a digest and nothing else. It
 * persists nothing and never accepts a Request.
 */
final class WebhookSigner
{
    /**
     * The HMAC-SHA256 signature of "{timestamp}.{body}" keyed by the endpoint's
     * signing_secret. The receiver re-derives it the same way to verify the request.
     */
    public function sign(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac(algo: 'sha256', data: $this->signedPayload(body: $body, timestamp: $timestamp), key: $secret);
    }

    /**
     * The exact bytes that are signed: the timestamp, a dot separator, then the JSON
     * body. Exposed so a test (and a real receiver) can reconstruct it deterministically.
     */
    public function signedPayload(string $body, int $timestamp): string
    {
        return "{$timestamp}.{$body}";
    }
}
