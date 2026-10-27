<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use Vaulter\Attributes\Internal;
use Vaulter\Webhooks\Exceptions\WebhookResponseTooLargeException;

/**
 * Caps the webhook receiver's response body. A delivery needs only
 * the status code, so a declared Content-Length above the limit is refused from the
 * Guzzle on_headers hook — before the body is read — and the buffering sink is bounded
 * so an UNDECLARED oversize body spills to a temp file instead of RAM.
 */
#[Internal]
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

    /**
     * A sink that HARD-caps the body while it streams: the write that would take the total
     * past $maxBytes throws before anything beyond the limit is kept, so an absent, false or
     * chunked response is aborted mid-read instead of spilling to a temp file. Combined with
     * `decode_content=false` the counted bytes are the wire bytes, never a decompression bomb.
     */
    public static function boundedSink(int $maxBytes): StreamInterface
    {
        return new class(Utils::streamFor(''), $maxBytes) implements StreamInterface
        {
            use StreamDecoratorTrait;

            private int $written = 0;

            public function __construct(StreamInterface $stream, private readonly int $maxBytes)
            {
                $this->stream = $stream;
            }

            public function write(string $string): int
            {
                if ($this->written + strlen($string) > $this->maxBytes) {
                    $this->stream->close();

                    throw WebhookResponseTooLargeException::streamed(limit: $this->maxBytes);
                }

                $this->written += strlen($string);

                return $this->stream->write($string);
            }
        };
    }
}
