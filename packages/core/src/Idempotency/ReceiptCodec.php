<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

use LogicException;
use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * How the owner of an idempotent operation stores its result in a receipt and restores it on a replay (
 * docs/adr/0029-idempotency.md). Versioned: a stored body is decoded with the version it was written with, and an unknown version
 * throws instead of guessing. A receipt of a destructive operation keeps only ids and status, never content.
 */
#[Spi(SpiKind::Implement)]
interface ReceiptCodec
{
    /** The stable name of the receipt format (an identity: `drive.node-snapshot`). */
    public function kind(): string;

    /** The version {@see encode()} writes; positive. */
    public function version(): int;

    /**
     * @return array<string, mixed> JSON-encodable
     */
    public function encode(mixed $result): array;

    /**
     * @param array<string, mixed> $body
     *
     * @throws LogicException for a version this codec does not know
     */
    public function decode(array $body, int $version): mixed;

    /** The resource the result is about, checked again on a replay; null when there is none. */
    public function resource(mixed $result): ?ResourceRef;
}
