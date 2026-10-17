<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Webhooks\Exceptions\InvalidWebhookSignatureException;

/**
 * Receiver-side verification of a Standard Webhooks request. Hosts that consume Vaulter
 * webhooks (and the package tests) use it instead of re-implementing the scheme:
 * every failure is one InvalidWebhookSignatureException, so a receiver answers 400
 * without telling the sender which check failed.
 *
 * Several secrets (the current and the one still valid after a rotation) and several
 * space-separated signatures in the header are accepted; one match is enough.
 */
#[Api]
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
        // A header may arrive as a list (Symfony's bag). The tolerance check and the signature
        // must see the same id and timestamp, so a second, different value is refused rather
        // than letting a stale signed timestamp ride behind a fresh one.
        $single = [];
        $lists = [];
        foreach ($headers as $name => $value) {
            $values = array_values(array_filter(is_array($value) ? $value : [$value], is_string(...)));
            if ($values !== []) {
                $lists[strtolower((string) $name)] = array_merge($lists[strtolower((string) $name)] ?? [], $values);
            }
        }
        foreach (['webhook-id', 'webhook-timestamp'] as $name) {
            $unique = array_values(array_unique($lists[$name] ?? []));
            if (count($unique) > 1) {
                throw InvalidWebhookSignatureException::ambiguousHeaders();
            }
            $single[$name] = $unique[0] ?? '';
        }

        $id = $single['webhook-id'];
        $timestampHeader = $single['webhook-timestamp'];
        $signatures = implode(' ', $lists['webhook-signature'] ?? []);

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
