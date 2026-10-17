<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use DateTimeImmutable;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Configuration\DownloadsConfig;
use Vaulter\Drive\Data\FileStream;
use Vaulter\Drive\Data\Snapshots\Mapping\FileRevisionSnapshotMapper;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Files\ContentUnavailableException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Repositories\Content\ScanEvidenceRepository;
use Vaulter\Drive\Sharing\ShareContext;
use Vaulter\Drive\Sharing\ShareResolver;
use Vaulter\Media\Content\SignedContentUrl;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\DirectContentUrlSigner;

/** Every new byte or capability request enters fresh authorization and availability. */
#[Internal]
final readonly class ContentDelivery
{
    public function __construct(private NodeLocator $locator, private OperationGuard $access, private ContentAvailabilityGuard $availability, private DownloadGuards $guards, private BlobStore $blobs, private DownloadsConfig $downloads, private ScanEvidenceRepository $evidence, private FileRevisionSnapshotMapper $revisions) {}

    public function stream(DriveContext $context, Node $node, FileRevision $revision): FileStream
    {
        $node = $this->authorize($context, $node);
        $this->guards->check($context, $node, $revision);

        return $this->open($node, $revision);
    }

    public function streamForShare(ShareContext $context, Node $node, FileRevision $revision): FileStream
    {
        $context = app(ShareResolver::class)->refresh($context);
        $this->guards->check($context, $node, $revision);

        return $this->open($node, $revision);
    }

    public function directUrl(DriveContext $context, Node $node, FileRevision $revision, int $ttlSeconds): SignedContentUrl
    {
        $node = $this->authorize($context, $node);
        $this->guards->check($context, $node, $revision);
        if ($this->downloads->deliveryMode() !== 'direct_signed') {
            throw ProfileRestrictionException::forOperation('direct_content_url');
        }
        if ($ttlSeconds < 1 || $ttlSeconds > $this->downloads->directTtlSeconds()) {
            throw new \InvalidArgumentException('Direct URL TTL exceeds the configured bound.');
        }
        [, $selected] = $this->availability->selection($node, $revision);
        $identity = $this->evidence->identity($selected) ?? throw new ContentUnavailableException;
        $expiresAt = (new DateTimeImmutable)->modify('+'.$ttlSeconds.' seconds');
        $signer = app(DirectContentUrlSigner::class);
        $url = $signer->sign($identity, $expiresAt);
        if (!hash_equals($identity->fingerprint(), $url->contentIdentity) || $url->storageVersion !== $identity->storageVersion
            || $url->expiresAt > $expiresAt || $url->expiresAt <= new DateTimeImmutable) {
            throw new ContentUnavailableException;
        }

        return $url;
    }

    private function authorize(DriveContext $context, Node $node): Node
    {
        $node = $this->locator->find($context, $node);
        $this->access->authorize($context, Ability::Download, $node, OperationKind::ContentEgress);

        return $node;
    }

    private function open(Node $node, FileRevision $revision): FileStream
    {
        [$node, $revision, , $blob] = $this->availability->selection($node, $revision);
        $stream = $this->blobs->read($blob);
        if (!is_resource($stream)) {
            throw NodeNotFoundException::forId($node->id);
        }

        return new FileStream($stream, $revision->original_name ?: $node->name, $revision->mime_type ?? 'application/octet-stream', $revision->size_bytes, $revision->sha256, $this->revisions->map($revision, $node->file()->firstOrFail()));
    }
}
