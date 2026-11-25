<?php

declare(strict_types=1);

use Vaulter\Webhooks\Exceptions\WebhookResponseTooLargeException;
use Vaulter\Webhooks\ResponseSizeLimit;

/**
 * The webhook response body is capped: a delivery needs only the
 * status code, so a receiver announcing an oversized body is refused from the Guzzle
 * on_headers hook — before the body is buffered — closing the OOM/DoS vector. This
 * unit-tests the exact predicate the hook calls.
 */
it('refuses a declared response body above the limit', function (): void {
    expect(fn () => ResponseSizeLimit::assertContentLengthWithinLimit((string) (1024 * 1024), 65536))
        ->toThrow(WebhookResponseTooLargeException::class);
});

it('allows a small, absent or non-numeric declared body', function (): void {
    ResponseSizeLimit::assertContentLengthWithinLimit('100', 65536);
    ResponseSizeLimit::assertContentLengthWithinLimit('65536', 65536);
    ResponseSizeLimit::assertContentLengthWithinLimit(null, 65536);
    ResponseSizeLimit::assertContentLengthWithinLimit('not-a-number', 65536);

    // A non-positive ceiling disables the check.
    ResponseSizeLimit::assertContentLengthWithinLimit((string) (1024 * 1024), 0);

    expect(true)->toBeTrue();
});

it('enforces the smallest positive ceiling', function (): void {
    expect(fn () => ResponseSizeLimit::assertContentLengthWithinLimit('2', 1))
        ->toThrow(WebhookResponseTooLargeException::class);
});

// The cap holds WHILE streaming — absent, false or chunked Content-Length. The sink
// is a deterministic fixture of the local predicate, not a real cURL/TLS abort.
it('aborts a chunked or falsely-declared body mid-stream past the limit', function (): void {
    $sink = ResponseSizeLimit::boundedSink(10);
    $sink->write('12345');
    $sink->write('67890');

    expect(fn (): int => $sink->write('x'))->toThrow(WebhookResponseTooLargeException::class);
});

it('accepts a body exactly at the limit and a single oversized chunk is refused unbuffered', function (): void {
    $sink = ResponseSizeLimit::boundedSink(4);
    $sink->write('abcd');
    $sink->rewind();

    expect($sink->getContents())->toBe('abcd')
        ->and(fn (): int => ResponseSizeLimit::boundedSink(4)->write('abcde'))->toThrow(WebhookResponseTooLargeException::class);
});
