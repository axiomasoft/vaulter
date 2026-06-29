<?php

declare(strict_types=1);

namespace Vaulter\Data;

use Vaulter\Models\Node;
use Vaulter\Models\Storage;

/**
 * Command for UploadFileAction (§2.4 commit flow). Carries an already-normalised,
 * seekable byte stream plus the upload metadata — the StreamInterface / SplFileInfo
 * / UploadedFile ergonomics are normalised to a plain resource at the facade
 * (DriveContext::upload) so this command stays free of HTTP/PSR types and the
 * Action receives only bytes + intent.
 *
 * Two target shapes share one command:
 *   - new file  → $into set (or null = storage root) + $name; $targetNode null;
 *   - new version → $targetNode set (the existing kind=file node); $into/$name
 *     are ignored (the node keeps its place and name).
 */
final readonly class UploadFileData
{
    /**
     * @param resource $stream a seekable byte stream the BlobStore can stage
     */
    public function __construct(
        public Storage $storage,
        public mixed $stream,
        public string $originalName,
        public ?string $mimeType = null,
        public ?Node $into = null,
        public ?string $name = null,
        public ?Node $targetNode = null,
        public int|string|null $actorId = null,
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
