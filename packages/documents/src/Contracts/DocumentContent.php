<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * The decoded, immutable content of a document: what a codec returns and what the
 * working state and every checkpoint store.
 */
#[Spi(SpiKind::Implement)]
interface DocumentContent
{
    /**
     * The JSON payload to store.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /** sha256 of the canonical serialization; lets an autosave skip a write that changed nothing. */
    public function contentHash(): string;

    /** The byte size of the canonical JSON payload; the node's size_bytes and quota follow it. */
    public function sizeInBytes(): int;
}
