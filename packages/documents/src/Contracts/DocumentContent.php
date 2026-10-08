<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Vaulter\Contracts\Handlers\DocumentContent as KernelDocumentContent;

/**
 * The decoded, immutable content of a document: what a codec returns and what the
 * working state and every checkpoint store.
 *
 * It extends the kernel interface only while core's exporter contract still names
 * that one; implementations target this interface.
 */
interface DocumentContent extends KernelDocumentContent
{
    /**
     * The JSON payload to store.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /** sha256 of the canonical serialization. */
    public function contentHash(): string;

    public function sizeInBytes(): int;
}
