<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Events\Uploads\UploadAborted;
use Vaulter\Drive\Events\Uploads\UploadExpired;
use Vaulter\Drive\Events\Uploads\UploadInitiated;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventRecorder;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Kernel;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Tenancy\TenancyBoundary;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * The resumable-upload lifecycle coordinator. It owns:
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
 * staged temp + protocol state on abort/expire.
 */
final readonly class UploadSessionManager
{
    public function __construct(
        private Container $container,
        private UploadSessionStoreRepository $sessions,
        private UploadSessionReadRepository $sessionsRead,
        private StagedUpload $staged,
        private VaulterDatabase $database,
        private DriveConfig $driveConfig,
        private EventRecorder $recorder,
    ) {}

    /**
     * Open a resumable session. Validates the declared total against the
     * configured ceiling BEFORE any bytes are accepted (oversize rejected up front,
     * never staged), checks the actor's active-session cap, mints
     * a UNIQUE upload_id, stamps the TTL, and lets the protocol handler initialise
     * its side (S3 CreateMultipartUpload; a no-op for tus).
     */
    public function initiate(DriveContext $context, InitiateUploadInput $data): UploadSession
    {
        $this->assertWithinMaxBytes($data->totalSizeBytes);
        $this->assertWithinActiveSessionCap($context->actor->ref()->id);

        // The session row and its audit row commit together.
        return $this->database->transaction(function () use ($context, $data): UploadSession {
            $session = $this->sessions->create([
                'tenant_id' => $context->drive->tenant_id,
                'drive_id' => $context->driveId(),
                'upload_id' => (string) Str::uuid(),
                'target_node_id' => $data->targetNode instanceof Node ? $data->targetNode->getKey() : ($data->targetNode instanceof NodeSnapshot ? $data->targetNode->id : $data->targetNode),
                'target_parent_id' => $data->targetParent instanceof Node ? $data->targetParent->getKey() : ($data->targetParent instanceof NodeSnapshot ? $data->targetParent->id : $data->targetParent),
                'target_name' => $data->targetName,
                'protocol' => $data->protocol,
                'status' => UploadStatus::Initiated,
                'expected_sha256' => $data->expectedSha256,
                'total_size_bytes' => $data->totalSizeBytes,
                'received_bytes' => 0,
                'chunk_size' => $this->driveConfig->chunkSize(),
                'mime_type' => $data->mimeType,
                'metadata' => [...$data->metadata, 'actor_type' => $context->actor->ref()->type, 'expected_sequence' => $data->expectedSequence],
                'expires_at' => Carbon::now()->addHours($this->driveConfig->sessionTtlHours()),
                'created_by' => $context->actor->ref()->id,
            ]);

            $this->handlerFor($session)->initialise($session);
            $session = $session->refresh();
            $this->recorder->record(new UploadInitiated(session: $session, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $session;
        });
    }

    /**
     * The per-protocol handler for a session, resolved from the container by
     * the session's protocol key in the UploadProtocolHandler slot.
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
        // The one-shot direct protocol is staged and committed like a tus upload.
        $key = $protocol === UploadProtocol::Direct ? UploadProtocol::Tus->value : $protocol->value;

        return $this->container->make(Kernel::class)->extensions()->handler(UploadProtocolHandler::class, $key);
    }

    /**
     * Mark a session assembled once its handler reports every byte received —
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
     * Every attempt re-reads the session under its lock. More than one attempt is only
     * for operations whose inputs survive a rollback (retained staged bytes), never for
     * a PATCH/part body that was already consumed from the request stream.
     *
     * @template TResult
     *
     * @param  callable(UploadSession): TResult $operation
     * @param  int<1, max>                      $attempts
     * @return TResult
     */
    public function withSession(UploadSession $session, callable $operation, int $attempts = 1): mixed
    {
        $this->assertTenant($session);

        return $this->database->retryingOutermost(fn (): mixed => $this->database->transaction(function () use ($session, $operation): mixed {
            return $operation($this->sessions->acquireForPatch($session));
        }, attempts: 1), $attempts);
    }

    /**
     * Drive assemble→commit INSIDE the same critical section as a tus PATCH. The controller used to do it in the open: `markAssembled()` then
     * `CommitUploadAction::execute()`, each on its own connection-level transaction, with
     * nothing holding the session lock in between — while {@see reclaim()} explicitly
     * reclaims an Assembled session and {@see UploadSession::scopeSweepable()} lists one
     * past its TTL. A sweeper tick therefore took the lock unopposed and discarded the
     * staged object MID-STREAM: the commit either died on "no staged bytes" or wrote a
     * TRUNCATED blob (the CAS re-hashes whatever it is handed — nothing downstream could
     * tell). Holding the lock across the whole "assemble → BlobStore.commit → markCommitted"
     * window makes the sweeper's non-blocking acquire fail instead, which it already reads
     * as "skip, this session is alive".
     *
     * The status is RE-READ under the lock: a session reclaimed between the last chunk and
     * this call is refused with a conflict and $commit is never invoked — no bytes of a
     * terminal session ever reach the Drive.
     *
     * @template TResult
     *
     * @param  callable(UploadSession): TResult $commit
     * @param  int<1, max>                      $attempts honoured only when this is the outermost unit of work
     * @return TResult
     */
    public function commitAssembled(UploadSession $session, callable $commit, int $attempts = 1): mixed
    {
        $this->assertTenant($session);

        return $this->database->retryingOutermost(fn (): mixed => $this->database->transaction(function () use ($session, $commit): mixed {
            $locked = $this->sessions->acquireForPatch($session);

            if ($locked->status === UploadStatus::Committed) {
                return $commit($locked);
            }

            if ($locked->status !== UploadStatus::Assembled) {
                if (!$locked->status->canTransitionTo(UploadStatus::Assembled)) {
                    throw UploadConflictException::forClosedSession($locked->upload_id);
                }

                $locked = $this->sessions->transition($locked, UploadStatus::Assembled);
            }

            return $commit($locked);
        }, attempts: 1), $attempts);
    }

    /**
     * Abort a session: reclaim the protocol-side state (S3 AbortMultipartUpload),
     * discard the staged temp, and tombstone the row as Aborted. Idempotent — a
     * terminal session is returned untouched (and, since nothing changed, emits no
     * activity — traces state CHANGES, not no-op calls).
     *
     * Runs inside the SAME critical section as a tus PATCH —
     * see {@see reclaim()}. Throws UploadConflictException when a PATCH holds the lock.
     * Without an actor the abort is recorded as a system reclaim.
     */
    public function abort(UploadSession $session, ?ActorRef $actor = null, ?string $correlationId = null): UploadSession
    {
        return $this->reclaim(
            session: $session,
            terminal: UploadStatus::Aborted,
            audit: fn (UploadSession $aborted): UploadAborted => new UploadAborted(
                session: $aborted,
                actor: $actor ?? Actor::system('upload abort')->ref(),
                correlationId: $correlationId ?? (string) Str::ulid(),
            ),
        );
    }

    /**
     * Expire a stale session (sweeper): the same cleanup as abort but the
     * terminal state is Expired (so the audit distinguishes a swept session from a
     * client-cancelled one). Runs outside an HTTP request (console/schedule) — the
     * activity row carries no actor (a system reclaim).
     *
     * Runs inside the SAME critical section as a tus PATCH —
     * see {@see reclaim()}. Throws UploadConflictException when a PATCH holds the lock;
     * the sweeper treats that as "skip, it is alive", not as a failure.
     */
    public function expire(UploadSession $session): UploadSession
    {
        return $this->reclaim(
            session: $session,
            terminal: UploadStatus::Expired,
            audit: static fn (UploadSession $expired): UploadExpired => new UploadExpired(
                session: $expired,
                actor: Actor::system('upload expiry')->ref(),
                correlationId: (string) Str::ulid(),
            ),
        );
    }

    /**
     * The shared abort/expire body — and the reason both exist at all: reclaiming a
     * session DESTROYS bytes (staged temp discard) and moves the row to a terminal
     * state, which is exactly the window a concurrent tus PATCH is writing into
     * .
     *
     * Before the fix, expire() ran with no lock at all: the sweeper could delete the
     * staged object between a PATCH's "read offset" and its "append", so the PATCH wrote
     * its chunk alone (losing the accepted prefix) and then forced the row back to
     * Uploading — a resurrected session pointing at a truncated file, with no error
     * anywhere. The barrier therefore has to stand on the path that DELIVERS the harm:
     *
     *   1. one transaction, taking the SAME per-session lock as the PATCH as its first
     *      statement (a PATCH in flight → UploadConflictException, no wait);
     *   2. the status is RE-READ under that lock (the row may have moved since the
     *      caller/sweeper selected it) — a session that is no longer reclaimable is
     *      returned untouched, and nothing is discarded;
     *   3. only then are the protocol state and the staged bytes released.
     *
     * @param callable(UploadSession): DomainEvent $audit the event of the reclaim, recorded last
     */
    private function reclaim(UploadSession $session, UploadStatus $terminal, callable $audit): UploadSession
    {
        $this->assertTenant($session);

        return $this->database->transaction(function () use ($session, $terminal, $audit): UploadSession {
            $locked = $this->sessions->acquireForPatch($session);

            // Re-read under the lock: another actor may have reclaimed/committed it
            // between the caller's SELECT and our lock. Terminal → no bytes to discard.
            if (!$locked->status->isOpen() && $locked->status !== UploadStatus::Assembled) {
                return $locked;
            }

            $this->handlerFor($locked)->abort($locked);
            $this->staged->discard($locked);

            $reclaimed = $this->sessions->transition($locked, $terminal);

            $this->recorder->record($audit($reclaimed));

            return $reclaimed;
        });
    }

    /**
     * Reject an oversize upload at initiate (HARDENING): a declared total past
     * the configured ceiling never opens a session, so we never stage bytes we must
     * reject. A null total (size unknown up front) is allowed; the tus PATCH path
     * still re-checks the ceiling as the offset grows.
     */
    private function assertTenant(UploadSession $session): void
    {
        if (!app(TenancyConfig::class)->enabled()) {
            return;
        }
        $class = app(DriveConfig::class)->model('upload_session');
        $fresh = $class::query()->whereKey($session->getRawOriginal($session->getKeyName()))->first();
        if (!$fresh instanceof UploadSession) {
            throw NodeNotFoundException::forId('upload');
        }
        $driveClass = app(VaulterConfig::class)->model('drive');
        $drive = $driveClass::query()->whereKey($fresh->drive_id)->first();
        if (!$drive instanceof Drive) {
            throw NodeNotFoundException::forId('drive');
        }
        app(TenancyBoundary::class)->assertDrive($drive);
        if (TenancyBoundary::normalize($fresh->tenant_id) !== TenancyBoundary::normalize($drive->tenant_id)) {
            throw NodeNotFoundException::forId('upload');
        }
    }

    private function assertWithinMaxBytes(?int $totalSizeBytes): void
    {
        $max = $this->driveConfig->maxFileSize();

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
     * — an actor may not open an unbounded number of
     * concurrent staging areas. 0 disables the cap; a null actor id (unattributed/
     * system-minted session) is never capped (there is no per-actor bucket).
     */
    private function assertWithinActiveSessionCap(int|string|null $actorId): void
    {
        $max = $this->driveConfig->maxActiveSessionsPerActor();

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
}
