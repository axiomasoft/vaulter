<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Packman\Attributes\Internal;

/**
 * Signs webhook deliveries as the Standard Webhooks spec defines: HMAC-SHA256 over
 * "{webhook-id}.{webhook-timestamp}.{body}" keyed by the decoded whsec_ secret,
 * base64-encoded and prefixed "v1,". Binding the id and the timestamp into the signed
 * bytes is what lets a receiver both deduplicate by id and reject a replayed request.
 *
 * Stateless: it computes digests and nothing else.
 */
#[Internal]
final class WebhookSigner
{
    public function sign(string $webhookId, int $timestamp, string $body, string $secret): string
    {
        return 'v1,'.base64_encode(hash_hmac(
            algo: 'sha256',
            data: "{$webhookId}.{$timestamp}.{$body}",
            key: WebhookSecret::keyBytes($secret),
            binary: true,
        ));
    }

    /**
     * The webhook-signature header value: one signature per secret, space separated,
     * in the given order (callers list the current secret first).
     *
     * @param list<string> $secrets
     */
    public function signatureHeader(string $webhookId, int $timestamp, string $body, array $secrets): string
    {
        return implode(' ', array_map(
            fn (string $secret): string => $this->sign(webhookId: $webhookId, timestamp: $timestamp, body: $body, secret: $secret),
            $secrets,
        ));
    }
}
