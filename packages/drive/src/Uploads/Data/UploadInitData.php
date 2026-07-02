<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Data;

use Vaulter\Drive\Uploads\Enums\UploadProtocol;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;

/**
 * Command for UploadSessionManager::initiate (§2.8 / D16 / M3a): everything needed
 * to open a resumable session, free of HTTP/PSR types (the controller resolves the
 * route-bound storage / target node and decodes the tus metadata before building
 * this).
 *
 * Two target shapes share one command (mirrors UploadFileData):
 *   - new file    → $targetParent set (or null = storage root) + $targetName;
 *     $targetNode null;
 *   - new version → $targetNode set (the existing kind=file node); $targetParent /
 *     $targetName are ignored.
 *
 * The client may DECLARE $expectedSha256 / $totalSizeBytes, but the assembled bytes
 * are always re-hashed server-side at commit (H.3) — these are integrity hints, not
 * the content address.
 *
 * @property array<string, string> $metadata the decoded tus Upload-Metadata
 */
final readonly class UploadInitData
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public Storage $storage,
        public UploadProtocol $protocol,
        public ?Node $targetParent = null,
        public ?string $targetName = null,
        public ?Node $targetNode = null,
        public ?string $expectedSha256 = null,
        public ?int $totalSizeBytes = null,
        public ?string $mimeType = null,
        public array $metadata = [],
        public int|string|null $actorId = null,
    ) {}

    /**
     * Whether this session commits a new version onto an existing file node rather
     * than creating a brand-new file.
     */
    public function isNewVersion(): bool
    {
        return $this->targetNode !== null;
    }
}
