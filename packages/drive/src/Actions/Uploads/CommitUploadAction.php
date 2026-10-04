<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Uploads;

use RuntimeException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\UploadFileAction;
use Vaulter\Drive\Data\UploadFileData;
use Vaulter\Drive\Exceptions\UploadContentMismatchException;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Support\Uploads\MagicByteSniffer;
use Vaulter\Drive\Support\Uploads\StagedUpload;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;

/**
 * Assemble→commit for a resumable upload (§2.8 / D16 / M3a / H.3). This is the ONE
 * place the staged bytes of a tus / S3-multipart session cross into the Drive, and
 * it does so through the SAME race-free path a one-shot upload uses:
 *
 *   staged temp → UploadFileData → UploadFileAction → BlobStore.commitStaged
 *
 * so dedup + the race-free state machine + ref accounting + quota all apply, and the
 * sha256 is RECOMPUTED server-side from the assembled staged file inside
 * BlobStore.commitStaged (the client's expected_sha256 / the S3 multipart ETag is NEVER
 * the content address — H.3). The session's target shapes UploadFileData:
 *   - new file    → a Node(kind=file) under target_parent (null = root);
 *   - new version → a new version of node_id (version_no bumps, head repoints).
 *
 * On success the session is marked Committed with assembled_blob_id, and the staged
 * temp is discarded (commit-or-abort cleanup, H.3). The whole thing is one outer
 * transaction so a session is never half-committed.
 */
final readonly class CommitUploadAction
{
    public function __construct(
        private UploadFileAction $uploadFile,
        private NodeReadRepository $nodes,
        private DriveReadRepository $storages,
        private UploadSessionStoreRepository $sessions,
        private StagedUpload $staged,
        private MagicByteSniffer $sniffer,
        private VaulterDatabase $database,
    ) {}

    /**
     * Commit the assembled bytes of a session into the Drive and finalise the
     * session. Returns the created/updated file node.
     */
    public function execute(UploadSession $session): Node
    {
        $stream = $this->staged->readStream($session);

        if (!is_resource($stream)) {
            throw new RuntimeException(
                "Cannot commit upload session [{$session->upload_id}]: no staged bytes are present.",
            );
        }

        $this->assertContentMatchesDeclaredMime($session);

        try {
            $node = $this->database->transaction(function () use ($session, $stream): Node {
                $committed = $this->uploadFile->execute($this->dataFor(session: $session, stream: $stream));

                // current_version_id is the head pointer; the version's blob_id is the
                // assembled blob the bytes deduped into (H.3 — set from the committed
                // version, never the client claim).
                $blobId = $this->assembledBlobId($committed);

                if ($blobId !== null) {
                    $this->sessions->markCommitted(session: $session, assembledBlobId: $blobId);
                }

                return $committed;
            });
        } finally {
            // $stream is guaranteed a live resource here (guarded above, never closed
            // inside the try) — close it once on both the success and throw paths.
            fclose($stream);
        }

        // Commit-or-abort cleanup (H.3): the staged temp is reclaimed once the bytes
        // are durable in the CAS. Done OUTSIDE the transaction so a disk-delete hiccup
        // never rolls back a committed file.
        $this->staged->discard($session);

        return $node;
    }

    /**
     * Reject a commit whose assembled bytes contradict the declared mime (§2.8 /
     * F-P1.4-11). Sniffed on a SEPARATE staged stream so the commit stream stays at
     * its start. A concrete contradiction (declared image/png, magic bytes ZIP)
     * fails the commit before any blob is written.
     */
    private function assertContentMatchesDeclaredMime(UploadSession $session): void
    {
        $sniff = $this->staged->readStream($session);

        if (!is_resource($sniff)) {
            return;
        }

        try {
            $matches = $this->sniffer->matches($sniff, $session->mime_type);
        } finally {
            fclose($sniff);
        }

        if (!$matches) {
            throw UploadContentMismatchException::forSession(
                $session->upload_id,
                (string) $session->mime_type,
            );
        }
    }

    /**
     * Build the UploadFileData for the session's target shape (§2.8). The stream is
     * the assembled staged temp; the Action stages + re-hashes it.
     *
     * @param resource $stream
     */
    private function dataFor(UploadSession $session, mixed $stream): UploadFileData
    {
        $storage = $this->storageOf($session);

        if ($session->isNewVersion()) {
            return new UploadFileData(
                storage: $storage,
                stream: $stream,
                originalName: $session->target_name ?? $this->targetNodeName($session) ?? 'upload.bin',
                mimeType: $session->mime_type,
                targetNode: $this->targetNode($session),
                actorId: $session->created_by,
            );
        }

        return new UploadFileData(
            storage: $storage,
            stream: $stream,
            originalName: $session->target_name ?? 'upload.bin',
            mimeType: $session->mime_type,
            into: $this->targetParent($session),
            name: $session->target_name,
            actorId: $session->created_by,
        );
    }

    /**
     * The session's storage, loaded non-null (a session always belongs to a storage
     * via FK cascade — a missing one is a corrupt state, fail loud rather than
     * commit into the void).
     */
    private function storageOf(UploadSession $session): Drive
    {
        $storage = $this->storages->find($session->storage_id);

        if (!$storage instanceof Drive) {
            throw new RuntimeException(
                "Cannot commit upload session [{$session->upload_id}]: its storage [{$session->storage_id}] is missing.",
            );
        }

        return $storage;
    }

    private function targetParent(UploadSession $session): ?Node
    {
        if ($session->target_parent_id === null) {
            return null;
        }

        return $this->nodes->find($session->target_parent_id);
    }

    private function targetNode(UploadSession $session): ?Node
    {
        if ($session->node_id === null) {
            return null;
        }

        return $this->nodes->find($session->node_id);
    }

    private function targetNodeName(UploadSession $session): ?string
    {
        return $this->targetNode($session)?->name;
    }

    /**
     * The assembled blob id from the committed file node's head version (§2.8). Read
     * from the persisted version row so it reflects the deduped blob the CAS chose,
     * not anything the client supplied.
     */
    private function assembledBlobId(Node $node): int|string|null
    {
        $version = $node->currentVersion()->first();

        return $version?->blob_id;
    }
}
