<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use InvalidArgumentException;
use Vaulter\Exceptions\Webhooks\InvalidWebhookSignatureException;

/**
 * Receiver-side verification of a Standard Webhooks request. Hosts that consume Vaulter
 * webhooks (and the package tests) use it instead of re-implementing the scheme:
 * every failure is one InvalidWebhookSignatureException, so a receiver answers 400
 * without telling the sender which check failed.
 *
 * Several secrets (the current and the one still valid after a rotation) and several
 * space-separated signatures in the header are accepted; one match is enough.
 */
final class StandardWebhookVerifier
{
    public function __construct(private readonly WebhookSigner $signer = new WebhookSigner) {}

    /**
     * @param array<string, mixed> $headers header name (any case) => value, or a list of values
     * @param list<string>         $secrets whsec_ secrets the receiver trusts
     * @param int|null             $now     unix time to compare against; defaults to the clock
     *
     * @throws InvalidWebhookSignatureException
     */
    public function verify(array $headers, string $body, array $secrets, int $toleranceSeconds = 300, ?int $now = null): void
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $first = is_array($value) ? ($value[0] ?? null) : $value;
            if (is_string($first)) {
                $normalized[strtolower((string) $name)] = $first;
            }
        }

        $id = $normalized['webhook-id'] ?? '';
        $timestampHeader = $normalized['webhook-timestamp'] ?? '';
        $signatures = $normalized['webhook-signature'] ?? '';

        if ($id === '' || $timestampHeader === '' || $signatures === '') {
            throw InvalidWebhookSignatureException::missingHeaders();
        }

        if (preg_match('/^\d{1,12}$/', $timestampHeader) !== 1) {
            throw InvalidWebhookSignatureException::invalidTimestamp();
        }

        $timestamp = (int) $timestampHeader;

        if (abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            throw InvalidWebhookSignatureException::timestampOutsideTolerance();
        }

        $received = [];
        foreach (preg_split('/\s+/', trim($signatures)) ?: [] as $candidate) {
            if (str_starts_with($candidate, 'v1,')) {
                $received[] = $candidate;
            }
        }

        foreach ($secrets as $secret) {
            try {
                $expected = $this->signer->sign(webhookId: $id, timestamp: $timestamp, body: $body, secret: $secret);
            } catch (InvalidArgumentException) {
                continue; // an unusable secret cannot match; the others still may
            }

            // Compare against every candidate, no early exit on the first mismatch.
            $matched = false;
            foreach ($received as $candidate) {
                $matched = hash_equals($expected, $candidate) || $matched;
            }

            if ($matched) {
                return;
            }
        }

        throw InvalidWebhookSignatureException::noMatchingSignature();
    }
}
