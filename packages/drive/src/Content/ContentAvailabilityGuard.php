<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Packman\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Contracts\Content\ScanPolicyResolver;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Drive\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Exceptions\Files\ContentUnavailableException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\Content\ScanEvidenceRepository;
use Vaulter\Drive\Sharing\ShareContext;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;

/**
 * The drive's `drive.content-availability` contribution to the DownloadGuard slot. Availability never grants
 * ACL or a private scanner capability.
 */
#[Internal]
final readonly class ContentAvailabilityGuard implements DownloadGuard
{
    public function __construct(private DriveOptions $config, private ScanPolicyResolver $policies, private ScanEvidenceRepository $evidence, private ScanSnapshot $snapshots) {}

    /**
     * The slot entry: the snapshots name the selected node and revision, the current rows are read by their ids and
     * judged as they are now, never as the snapshot remembers them (a quarantine or a trash after it still denies).
     */
    public function check(DriveContext|ShareLinkSnapshot $context, NodeSnapshot $node, FileRevisionSnapshot $revision): void
    {
        $nodeClass = $this->config->model('node');
        $revisionClass = $this->config->model('file_revision');
        $nodeRow = $nodeClass::query()->whereKey($node->id)->first() ?? throw NodeNotFoundException::forId($node->id);
        $revisionRow = $revisionClass::query()->whereKey($revision->id)->first() ?? throw NodeNotFoundException::forId($node->id);
        if (!$context instanceof ShareLinkSnapshot) {
            $this->assertAvailable($context, $nodeRow, $revisionRow);

            return;
        }

        // The share is read again by id: its current target bounds the delivery, not the snapshot's.
        $shareClass = $this->config->model('node_share');
        $share = $shareClass::query()->whereKey($context->id)->first() ?? throw NodeNotFoundException::forId($node->id);
        $target = $share->node()->first() ?? throw NodeNotFoundException::forId($node->id);
        $this->assertAvailableForShare(new ShareContext($share, $target, $share->role()), $nodeRow, $revisionRow);
    }

    public function assertAvailable(DriveContext $context, Node $node, FileRevision $revision): void
    {
        [, $actualRevision, $drive] = $this->selection($node, $revision);
        if ((string) $drive->id !== (string) $context->driveId()) {
            throw NodeNotFoundException::forId($node->id);
        }
        app(TenancyBoundary::class)->assertDrive($drive);
        $this->assertClean($actualRevision);
    }

    public function assertAvailableForShare(ShareContext $context, Node $node, FileRevision $revision): void
    {
        [$actualNode, $actualRevision, $drive] = $this->selection($node, $revision);
        if (!$context->containsNode($actualNode) || (string) $context->share->tenant_id !== (string) $drive->tenant_id) {
            throw NodeNotFoundException::forId($node->id);
        }
        $this->assertClean($actualRevision);
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
        // A blob the integrity scrub marked is media's state, read through the Api: its bytes are not handed out.
        if (!$blob instanceof Blob || $blob->status !== BlobStatus::Committed || $blob->chunked || !$blob->isIntact()
            || !$actualRevision->blobs()->whereKey($blob->id)->exists()
            || $blob->size_bytes !== $actualRevision->size_bytes || !hash_equals($blob->sha256, $actualRevision->sha256)
            || ($blob->tenant_id !== null && (string) $blob->tenant_id !== (string) $drive->tenant_id)
            || ($blob->storage_id !== null && (string) $blob->storage_id !== (string) $drive->id)) {
            throw new ContentUnavailableException;
        }

        return [$actualNode, $actualRevision, $drive, $blob];
    }

    /**
     * The revision's scope and the drive's policy states are read once and every check below judges those rows:
     * re-reading them per spec cost a dozen queries per spec on each download without adding any guarantee, since
     * the separate reads were never a consistent view either.
     */
    private function assertClean(FileRevision $revision): void
    {
        $snapshot = $this->snapshots->load($revision);
        if ($this->evidence->infectedIn($snapshot)) {
            throw new ContentUnavailableException;
        }
        foreach ($this->policies->requiredSpecs($snapshot['drive'], $snapshot['revision']) as $spec) {
            if ($this->evidence->compatibleIn($snapshot, $spec) === null) {
                throw new ContentUnavailableException;
            }
        }
    }
}
