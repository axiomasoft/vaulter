<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Vaulter\Documents\Exceptions\InvalidDocumentContentException;

/** Turns raw client JSON into the content of one document type. */
interface DocumentCodec
{
    /**
     * Validate and normalize raw client input.
     *
     * @param array<string, mixed> $raw
     *
     * @throws InvalidDocumentContentException
     */
    public function decode(array $raw, int $schemaVersion): DocumentContent;

    /** null = the global vaulter-documents.content.max_size_kb. */
    public function maxSizeBytes(): ?int;
}
