<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content;

use Vaulter\Attributes\Api;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;

/**
 * Accepts any JSON object and stores it as is. For types whose editor owns the
 * format (a spreadsheet workbook, a host's own widget); the server only bounds its
 * size.
 */
#[Api]
final readonly class OpaqueJsonCodec implements DocumentCodec
{
    public function __construct(private ?int $maxSizeBytes = null) {}

    /** @param array<array-key, mixed> $raw */
    public function decode(array $raw, int $schemaVersion): DocumentContent
    {
        if ($raw !== [] && array_is_list($raw)) {
            throw InvalidDocumentContentException::notAnObject();
        }

        $object = [];
        foreach ($raw as $key => $value) {
            $object[(string) $key] = $value;
        }

        return OpaqueContent::fromArray($object);
    }

    public function maxSizeBytes(): ?int
    {
        return $this->maxSizeBytes;
    }
}
