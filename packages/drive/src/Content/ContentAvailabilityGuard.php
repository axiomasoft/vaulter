<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Contracts\Content\ScanPolicyResolver;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Drive\Exceptions\Files\ContentUnavailableException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\Content\ScanEvidenceRepository;
use Vaulter\Drive\Sharing\ShareContext;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * The drive's `drive.content-availability` contribution to the DownloadGuard slot. Availability never grants
 * ACL or a private scanner capability.
 */
#[Internal]
final readonly class ContentAvailabilityGuard implements DownloadGuard
{
    public function __construct(private VaulterConfig $config, private ScanPolicyResolver $policies, private ScanEvidenceRepository $evidence) {}

    public function check(DriveContext|ShareContext $context, Node $node, FileRevision $revision): void
    {
        $context instanceof ShareContext
            ? $this->assertAvailableForShare($context, $node, $revision)
            : $this->assertAvailable($context, $node, $revision);
    }

    public function assertAvailable(DriveContext $context, Node $node, FileRevision $revision): void
    {
        [, $actualRevision, $drive] = $this->selection($node, $revision);
        if ((string) $drive->id !== (string) $context->driveId()) {
            throw NodeNotFoundException::forId($node->id);
        }
        app(TenancyBoundary::class)->assertDrive($drive);
        $this->assertClean($actualRevision, $drive);
    }

    public function assertAvailableForShare(ShareContext $context, Node $node, FileRevision $revision): void
    {
        [$actualNode, $actualRevision, $drive] = $this->selection($node, $revision);
        if (!$context->containsNode($actualNode) || (string) $context->share->tenant_id !== (string) $drive->tenant_id) {
            throw NodeNotFoundException::forId($node->id);
        }
        $this->assertClean($actualRevision, $drive);
    }

    /** @return array{Node,FileRevision,Drive,Blob} */
    public function selection(Node $node, FileRevision $revision): array
    {
        $nodeClass = $this->config->model('node');
        $actualNode = $nodeClass::query()->whereKey($node->getRawOriginal($node->getKeyName()))->whereNull('trashed_at')->first();
        if (!$actualNode instanceof Node) {
            throw NodeNotFoundException::forId($node->id);
        }
        $file = $actualNode->file()->first();
        $actualRevision = $file?->versions()->whereKey($revision->getRawOriginal($revision->getKeyName()))->whereIn('status', ['committed', 'superseded'])->first();
        $drive = $actualNode->storage()->first();
        if (!$actualRevision instanceof FileRevision || !$drive instanceof Drive || (string) $actualRevision->tenant_id !== (string) $drive->tenant_id) {
            throw NodeNotFoundException::forId($node->id);
        }
        $blob = $actualRevision->blob()->first();
        if (!$blob instanceof Blob || $blob->status !== BlobStatus::Committed || $blob->chunked
            || !$actualRevision->blobs()->whereKey($blob->id)->exists()
            || $blob->size_bytes !== $actualRevision->size_bytes || !hash_equals($blob->sha256, $actualRevision->sha256)
            || ($blob->tenant_id !== null && (string) $blob->tenant_id !== (string) $drive->tenant_id)
            || ($blob->storage_id !== null && (string) $blob->storage_id !== (string) $drive->id)) {
            throw new ContentUnavailableException;
        }

        return [$actualNode, $actualRevision, $drive, $blob];
    }

    private function assertClean(FileRevision $revision, Drive $drive): void
    {
        if ($this->evidence->infected($revision)) {
            throw new ContentUnavailableException;
        }
        foreach ($this->policies->resolve($drive) as $spec) {
            if ($this->policies->required($revision, $spec) && $this->evidence->compatible($revision, $spec) === null) {
                throw new ContentUnavailableException;
            }
        }
    }
}
