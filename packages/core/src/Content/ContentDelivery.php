<?php

declare(strict_types=1);

namespace Vaulter\Content;

use DateTimeImmutable;
use Vaulter\Access\OperationGuard;
use Vaulter\Configuration\DownloadsConfig;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Cas\DirectContentUrlSigner;
use Vaulter\Data\Drive\FileStream;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Files\ContentUnavailableException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Content\ScanEvidenceRepository;
use Vaulter\Sharing\ShareContext;
use Vaulter\Sharing\ShareResolver;

/** Every new byte or capability request enters fresh authorization and availability. */
final readonly class ContentDelivery
{
    public function __construct(private NodeLocator $locator, private OperationGuard $access, private ContentAvailabilityGuard $availability, private BlobStore $blobs, private DownloadsConfig $downloads, private ScanEvidenceRepository $evidence) {}

    public function stream(OperationContext $context, Node $node, FileRevision $revision): FileStream
    {
        $node = $this->authorize($context, $node);
        $this->availability->assertAvailable($context, $node, $revision);

        return $this->open($node, $revision);
    }

    public function streamForShare(ShareContext $context, Node $node, FileRevision $revision): FileStream
    {
        $context = app(ShareResolver::class)->refresh($context);
        $this->availability->assertAvailableForShare($context, $node, $revision);

        return $this->open($node, $revision);
    }

    public function directUrl(OperationContext $context, Node $node, FileRevision $revision, int $ttlSeconds): SignedContentUrl
    {
        $node = $this->authorize($context, $node);
        $this->availability->assertAvailable($context, $node, $revision);
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

    private function authorize(OperationContext $context, Node $node): Node
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

        return new FileStream($stream, $revision->original_name ?: $node->name, $revision->mime_type ?? 'application/octet-stream', $revision->size_bytes, $revision->sha256, $revision);
    }
}
