<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Files;

use Psr\Http\Message\StreamInterface;
use Vaulter\Drive\Uploads\PreparedUpload;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;

/**
 * Upload bytes and intent. The shared preparation reads the stream from its current
 * position, once, and keeps the prepared content across SQL retries.
 *
 * Two target shapes share one command:
 *   - new file  → $into set (or null = storage root) + $name; $targetNode null;
 *   - new version → $targetNode set (the existing kind=file node); $into/$name
 *     are ignored (the node keeps its place and name).
 */
final readonly class IngestFileInput
{
    /**
     * @param resource|StreamInterface $stream a borrowed stream at its current position
     */
    public function __construct(
        public Drive $storage,
        public mixed $stream,
        public string $originalName,
        public ?string $mimeType = null,
        public ?Node $into = null,
        public ?string $name = null,
        public ?Node $targetNode = null,
        public ?int $expectedSequence = null,
        public OnNameConflict $onConflict = OnNameConflict::Fail,
        public ?PreparedUpload $prepared = null,
        public ?string $declaredMimeType = null,
        public ?\DateTimeInterface $clientModifiedAt = null,
    ) {}

    /**
     * Whether this upload commits a new version onto an existing file node rather
     * than creating a brand-new file.
     */
    public function isNewVersion(): bool
    {
        return $this->targetNode !== null;
    }

    /**
     * The stored node name — the explicit override, else the original upload name.
     */
    public function resolvedName(): string
    {
        return $this->name ?? $this->originalName;
    }
}
