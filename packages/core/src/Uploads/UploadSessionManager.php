<?php

declare(strict_types=1);

namespace Vaulter\Uploads;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Vaulter\Contracts\UploadProtocolHandler;
use Vaulter\Data\Uploads\UploadInitData;
use Vaulter\Enums\UploadProtocol;
use Vaulter\Enums\UploadStatus;
use Vaulter\Models\UploadSession;
use Vaulter\Repositories\UploadSessionStoreRepository;
use Vaulter\Support\Config;

/**
 * The resumable-upload lifecycle coordinator (§2.8 / D16 / M3a). It owns:
 *   - initiate(UploadInitData) → a fresh UploadSession (status=initiated, a UNIQUE
 *     upload_id, expires_at = now + the configured TTL), oversize-rejected up front;
 *   - the lifecycle transitions (initiated → uploading → assembled → committed) plus
 *     abort/expire, each guarded by UploadStatus::canTransitionTo;
 *   - resolving the per-protocol handler (TusUploadHandler / S3MultipartHandler) for
 *     a session.
 *
 * It is read/CLI-context safe: it takes no Request and persists through the store
 * repository (never Model::create) so the controller stays thin and the sweeper /
 * queue can drive it. The actual assemble→commit (BlobStore.commit) is
 * CommitUploadAction — the manager only moves the session's state and cleans up the
 * staged temp + protocol state on abort/expire (H.3).
 */
final readonly class UploadSessionManager
{
    public function __construct(
        private Container $container,
        private UploadSessionStoreRepository $sessions,
        private StagedUpload $staged,
    ) {}

    /**
     * Open a resumable session (§2.8). Validates the declared total against the
     * configured ceiling BEFORE any bytes are accepted (oversize rejected up front,
     * never staged), mints a UNIQUE upload_id, stamps the TTL, and lets the protocol
     * handler initialise its side (S3 CreateMultipartUpload; a no-op for tus).
     */
    public function initiate(UploadInitData $data): UploadSession
    {
        $this->assertWithinMaxBytes($data->totalSizeBytes);

        $session = $this->sessions->create([
            'tenant_id' => $this->tenantId($data),
            'storage_id' => $data->storage->getKey(),
            'upload_id' => (string) Str::uuid(),
            'node_id' => $data->targetNode?->getKey(),
            'target_parent_id' => $data->targetParent?->getKey(),
            'target_name' => $data->targetName,
            'protocol' => $data->protocol,
            'status' => UploadStatus::Initiated,
            'expected_sha256' => $data->expectedSha256,
            'total_size_bytes' => $data->totalSizeBytes,
            'received_bytes' => 0,
            'chunk_size' => Config::uploadChunkSize(),
            'mime_type' => $data->mimeType,
            'metadata' => $data->metadata === [] ? null : $data->metadata,
            'expires_at' => Carbon::now()->addHours(Config::uploadSessionTtlHours()),
            'created_by' => $data->actorId,
        ]);

        $this->handlerFor($session)->initialise($session);

        return $session->refresh();
    }

    /**
     * The per-protocol handler for a session (§2.8), resolved from the container by
     * the session's protocol enum so a host may swap either implementation.
     */
    public function handlerFor(UploadSession $session): UploadProtocolHandler
    {
        return $this->handlerForProtocol($session->protocol);
    }

    /**
     * The handler for a protocol value (used before a session exists).
     */
    public function handlerForProtocol(UploadProtocol $protocol): UploadProtocolHandler
    {
        return match ($protocol) {
            UploadProtocol::Tus, UploadProtocol::Direct => $this->container->make(TusUploadHandler::class),
            UploadProtocol::S3Multipart => $this->container->make(S3MultipartHandler::class),
        };
    }

    /**
     * Mark a session assembled (§2.8) once its handler reports every byte received —
     * the gate the commit step waits on. A no-op (returns as-is) when the session is
     * not actually complete, so a premature complete() call cannot skip ahead.
     */
    public function markAssembled(UploadSession $session): UploadSession
    {
        if ($session->status === UploadStatus::Assembled) {
            return $session;
        }

        $this->guardTransition($session, UploadStatus::Assembled);

        return $this->sessions->transition($session, UploadStatus::Assembled);
    }

    /**
     * Abort a session (§2.8): reclaim the protocol-side state (S3 AbortMultipartUpload),
     * discard the staged temp, and tombstone the row as Aborted. Idempotent — a
     * terminal session is returned untouched.
     */
    public function abort(UploadSession $session): UploadSession
    {
        if (!$session->status->isOpen() && $session->status !== UploadStatus::Assembled) {
            return $session;
        }

        $this->handlerFor($session)->abort($session);
        $this->staged->discard($session);

        return $this->sessions->transition($session, UploadStatus::Aborted);
    }

    /**
     * Expire a stale session (§2.8 sweeper): the same cleanup as abort but the
     * terminal state is Expired (so the audit distinguishes a swept session from a
     * client-cancelled one).
     */
    public function expire(UploadSession $session): UploadSession
    {
        $this->handlerFor($session)->abort($session);
        $this->staged->discard($session);

        return $this->sessions->transition($session, UploadStatus::Expired);
    }

    /**
     * Reject an oversize upload at initiate (§2.8 / HARDENING): a declared total past
     * the configured ceiling never opens a session, so we never stage bytes we must
     * reject. A null total (size unknown up front) is allowed; the tus PATCH path
     * still re-checks the ceiling as the offset grows.
     */
    private function assertWithinMaxBytes(?int $totalSizeBytes): void
    {
        $max = Config::uploadMaxBytes();

        if ($max === 0 || $totalSizeBytes === null) {
            return;
        }

        if ($totalSizeBytes > $max) {
            throw ValidationException::withMessages([
                'total_size_bytes' => "The upload size {$totalSizeBytes} exceeds the maximum allowed {$max} bytes.",
            ]);
        }
    }

    private function guardTransition(UploadSession $session, UploadStatus $target): void
    {
        if (!$session->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition upload session from {$session->status->value} to {$target->value}.",
            ]);
        }
    }

    private function tenantId(UploadInitData $data): int|string|null
    {
        return $data->storage->tenant_id;
    }
}
