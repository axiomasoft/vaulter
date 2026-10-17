<?php

declare(strict_types=1);

use Vaulter\Webhooks\Exceptions\WebhookResponseTooLargeException;
use Vaulter\Webhooks\ResponseSizeLimit;

/**
 * The webhook response body is capped (§2.11 / F-P1.7-06): a delivery needs only the
 * status code, so a receiver announcing an oversized body is refused from the Guzzle
 * on_headers hook — before the body is buffered — closing the OOM/DoS vector. This
 * unit-tests the exact predicate the hook calls.
 */
it('refuses a declared response body above the limit, F-P1.7-06', function (): void {
    expect(fn () => ResponseSizeLimit::assertContentLengthWithinLimit((string) (1024 * 1024), 65536))
        ->toThrow(WebhookResponseTooLargeException::class);
});

it('allows a small, absent or non-numeric declared body, F-P1.7-06', function (): void {
    ResponseSizeLimit::assertContentLengthWithinLimit('100', 65536);
    ResponseSizeLimit::assertContentLengthWithinLimit('65536', 65536);
    ResponseSizeLimit::assertContentLengthWithinLimit(null, 65536);
    ResponseSizeLimit::assertContentLengthWithinLimit('not-a-number', 65536);

    // A non-positive ceiling disables the check.
    ResponseSizeLimit::assertContentLengthWithinLimit((string) (1024 * 1024), 0);

    expect(true)->toBeTrue();
});

it('enforces the smallest positive ceiling, F-P1.7-06', function (): void {
    expect(fn () => ResponseSizeLimit::assertContentLengthWithinLimit('2', 1))
        ->toThrow(WebhookResponseTooLargeException::class);
});
