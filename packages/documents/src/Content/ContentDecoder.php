<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content;

use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Content\TipTap\TipTapCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\UpgradesSchema;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\Types\UnknownDocumentType;

/**
 * Turns client JSON into content through the document's type, and stored JSON back
 * into content for reading.
 *
 * Writing is strict: the type must be registered, its codec validates, and the size
 * limit (the codec's, else the global one) holds. Reading never fails: a type that is
 * gone reads as opaque JSON, an older schema is upgraded on the fly when the type can
 * (history is not rewritten), and content the codec only partly understands is marked
 * lossy so it is never saved back.
 *
 * @internal
 */
final readonly class ContentDecoder
{
    public function __construct(
        private DocumentTypeRegistry $types,
        private DocumentsConfig $config,
    ) {}

    /** The registered type, or the read-only stand-in for a type that is gone. */
    public function type(string $key): DocumentType
    {
        return $this->types->find($key) ?? new UnknownDocumentType($key);
    }

    public function available(string $key): bool
    {
        return $this->types->has($key);
    }

    /** @throws DocumentTypeUnavailableException */
    public function writable(string $key): DocumentType
    {
        return $this->types->find($key) ?? throw new DocumentTypeUnavailableException($key);
    }

    /**
     * Client input (null = the type's default content), validated by the type's codec.
     *
     * @param array<string, mixed>|null $raw
     *
     * @throws InvalidDocumentContentException
     */
    public function decode(DocumentType $type, ?array $raw): DocumentContent
    {
        $content = $raw === null ? $type->defaultContent() : $type->codec()->decode($raw, $type->schemaVersion());
        $limit = $type->codec()->maxSizeBytes() ?? $this->config->maxContentSizeKb() * 1024;

        if ($limit > 0 && $content->sizeInBytes() > $limit) {
            throw InvalidDocumentContentException::tooLargeBytes($content->sizeInBytes(), $limit);
        }

        return $content;
    }

    /**
     * Stored content for display.
     *
     * @param array<string, mixed>|null $stored
     */
    public function read(string $typeKey, ?array $stored, int $schemaVersion): ReadContent
    {
        $type = $this->types->find($typeKey);

        if ($type === null) {
            return new ReadContent(OpaqueContent::fromArray($stored ?? []), lossless: true);
        }
        if ($stored === null) {
            return new ReadContent($type->defaultContent(), lossless: true);
        }
        if ($schemaVersion < $type->schemaVersion() && $type instanceof UpgradesSchema) {
            $stored = $type->upgrade($stored, $schemaVersion);
        }

        $codec = $type->codec();
        if ($codec instanceof TipTapCodec) {
            return $codec->decodeForRead($stored);
        }

        try {
            return new ReadContent($codec->decode($stored, $type->schemaVersion()), lossless: true);
        } catch (InvalidDocumentContentException) {
            return new ReadContent(OpaqueContent::fromArray($stored), lossless: false);
        }
    }

    /**
     * Whether content stored in $schemaVersion may be edited by this installation: a
     * document written by a newer version of its type is shown, not overwritten.
     */
    public function schemaWritable(DocumentType $type, int $schemaVersion): bool
    {
        return $schemaVersion <= $type->schemaVersion();
    }
}
