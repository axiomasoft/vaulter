<?php

declare(strict_types=1);

namespace Vaulter\Tests\Idempotency\Fixtures;

use LogicException;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/** Stores a string result as is; version 1 only. */
final class EchoReceiptCodec implements ReceiptCodec
{
    public function kind(): string
    {
        return 'test.echo';
    }

    public function version(): int
    {
        return 1;
    }

    public function encode(mixed $result): array
    {
        return ['value' => (string) $result];
    }

    public function decode(array $body, int $version): string
    {
        if ($version !== 1) {
            throw new LogicException('unknown echo version '.$version);
        }

        return (string) $body['value'];
    }

    public function resource(mixed $result): ?ResourceRef
    {
        return null;
    }
}
