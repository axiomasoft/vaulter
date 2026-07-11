<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Vaulter\Drive\Events\UploadSessionAborted;
use Vaulter\Drive\Events\UploadSessionExpired;
use Vaulter\Drive\Events\UploadSessionInitiated;
use Vaulter\Drive\Uploads\Contracts\UploadProtocolHandler;
use Vaulter\Drive\Uploads\Data\UploadInitData;
use Vaulter\Drive\Uploads\Enums\UploadProtocol;
use Vaulter\Drive\Uploads\Enums\UploadStatus;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Drive\Uploads\Repositories\UploadSessionReadRepository;
use Vaulter\Drive\Uploads\Repositories\UploadSessionStoreRepository;
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
        private UploadSessionReadRepository $sessionsRead,
        private StagedUpload $staged,
    ) {}

    /**
     * Open a resumable session (§2.8). Validates the declared total against the
     * configured ceiling BEFORE any bytes are accepted (oversize rejected up front,
     * never staged), checks the actor's active-session cap (P1.3 / F-P1.4-09), mints
     * a UNIQUE upload_id, stamps the TTL, and lets the protocol handler initialise
     * its side (S3 CreateMultipartUpload; a no-op for tus).
     */
    public function initiate(UploadInitData $data): UploadSession
    {
        $this->assertWithinMaxBytes($data->totalSizeBytes);
        $this->assertWithinActiveSessionCap($data->actorId);

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

        $session = $session->refresh();

        // F-P1.4-13: the lifecycle audit gap — create had no activity trace at all.
        event(new UploadSessionInitiated($session, $data->actorId));

        return $session;
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
     * terminal session is returned untouched (and, since nothing changed, emits no
     * activity — F-P1.4-13 traces state CHANGES, not no-op calls).
     */
    public function abort(UploadSession $session, int|string|null $actorId = null): UploadSession
    {
        if (!$session->status->isOpen() && $session->status !== UploadStatus::Assembled) {
            return $session;
        }

        $this->handlerFor($session)->abort($session);
        $this->staged->discard($session);

        $session = $this->sessions->transition($session, UploadStatus::Aborted);

        // F-P1.4-13: the lifecycle audit gap — abort had no activity trace at all.
        event(new UploadSessionAborted($session, $actorId));

        return $session;
    }

    /**
     * Expire a stale session (§2.8 sweeper): the same cleanup as abort but the
     * terminal state is Expired (so the audit distinguishes a swept session from a
     * client-cancelled one). Runs outside an HTTP request (console/schedule) — the
     * activity row carries no actor (a system reclaim).
     */
    public function expire(UploadSession $session): UploadSession
    {
        $this->handlerFor($session)->abort($session);
        $this->staged->discard($session);

        $session = $this->sessions->transition($session, UploadStatus::Expired);

        // F-P1.4-13: the lifecycle audit gap — expire had no activity trace at all.
        event(new UploadSessionExpired($session));

        return $session;
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

    /**
     * Refuse a new session past the configured cap on OPEN sessions per actor
     * (§2.8 / P1.3 / F-P1.4-09) — an actor may not open an unbounded number of
     * concurrent staging areas. 0 disables the cap; a null actor id (unattributed/
     * system-minted session) is never capped (there is no per-actor bucket).
     */
    private function assertWithinActiveSessionCap(int|string|null $actorId): void
    {
        $max = Config::maxActiveUploadSessions();

        if ($max === 0 || $actorId === null) {
            return;
        }

        if ($this->sessionsRead->countActiveForActor($actorId) >= $max) {
            throw new TooManyRequestsHttpException(
                retryAfter: null,
                message: "Too many active upload sessions ({$max} max) for this actor.",
            );
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
