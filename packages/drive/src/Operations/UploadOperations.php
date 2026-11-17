<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Closure;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Actions\Uploads\AbortUploadAction;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Actions\Uploads\InitiateUploadAction;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\UploadSessionSnapshot;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Idempotency\NodeReplayAuthorizer;
use Vaulter\Drive\Idempotency\UploadSessionCodec;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Drive\Uploads\CompletionClock;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;

#[Api]
final readonly class UploadOperations
{
    #[Internal]
    public function __construct(
        DriveHandle $handle,
        private DriveContext $context,
        private InitiateUploadAction $initiation,
        private CompleteUploadAction $completion,
        private AbortUploadAction $abortion,
        private UploadSessionManager $manager,
        private UploadSessionReadRepository $sessions,
        private TusUploadHandler $tus,
        private S3MultipartHandler $s3,
        private DriveOptions $config,
        private NodeLocator $locator,
        private OperationGuard $guard,
        private OperationRunner $runner,
        private CompletionClock $clock,
        private NodeSnapshotMapper $nodes,
    ) {
        $drive = $handle->model();
        $key = $drive->getRawOriginal($drive->getKeyName());
        if ((!is_int($key) && !is_string($key)) || (string) $key !== (string) $context->driveId()) {
            throw NodeNotFoundException::forId('drive');
        }
    }

    /** The opened session; a replayed key answers with the session its receipt kept, never a later re-read. */
    public function initiate(InitiateUploadInput $input): UploadSessionSnapshot
    {
        $node = $input->targetNode === null ? null : self::idOf($input->targetNode);
        $parent = $input->targetParent === null ? null : self::idOf($input->targetParent);
        // Idempotent by key: the receipt is the opened session. A replay needs Edit on the target file (a new
        // revision) or Create in the parent folder (a new file) where they are now.
        $idempotency = new IdempotentWrite(
            codec: new UploadSessionCodec($this->config),
            authorizer: $node === null
                ? NodeReplayAuthorizer::forCreate($this->context, $this->locator, $this->guard, $parent)
                : NodeReplayAuthorizer::forNode($this->context, $this->locator, $this->guard, Ability::Edit, $node),
            fingerprint: [
                'protocol' => $input->protocol->value, 'target_node' => $node, 'target_parent' => $parent, 'target_name' => $input->targetName,
                'expected_sha256' => $input->expectedSha256, 'total_size_bytes' => $input->totalSizeBytes, 'mime_type' => $input->mimeType,
                'metadata' => $input->metadata, 'expected_sequence' => $input->expectedSequence,
            ],
        );

        $session = $this->write('initiate', function () use ($input): UploadSession {
            if (!$this->config->uploads->resumable->enabled) {
                // The target is looked up first, so a disabled feature does not tell a
                // caller which ids exist: invisible → 404, visible → 403.
                $target = $this->locator->find(context: $this->context, key: $input->targetNode ?? $input->targetParent ?? $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'));
                $this->guard->authorize(context: $this->context, ability: Ability::View, node: $target);

                throw ProfileRestrictionException::forOperation('resumable_upload');
            }

            return $this->initiation->execute(context: $this->context, input: $input);
        }, $idempotency);

        return UploadSessionSnapshot::fromResult($session, $this->context->drive->tenant_id);
    }

    /** The file the session became; a repeated completion answers with the revision the first one committed. */
    public function complete(UploadSessionSnapshot|int|string $session): NodeSnapshot
    {
        $session = $this->find($session);
        // One finite monotonic budget for the whole request, started outside every
        // DB attempt: a retry spends what is left, it never gets a fresh deadline.
        $deadline = $this->clock->start();

        // The runner repeats the whole completion after a deadlock or serialization
        // failure (bounded): the staged protocol bytes survive a rollback.
        $node = $this->write('complete', fn (): Node => $this->manager->withSession($session, function (UploadSession $locked) use ($deadline): Node {
            if ($locked->status === UploadStatus::Committed) {
                return $this->completion->execute(context: $this->context, session: $locked, deadline: $deadline);
            }
            $this->completion->preflight(session: $locked, deadline: $deadline);
            $this->completion->authorizeSession(context: $this->context, session: $locked);
            $this->assertResumable();
            if ($locked->protocol === UploadProtocol::S3Multipart && $locked->status !== UploadStatus::Assembled) {
                $this->s3->complete($locked);
                $locked = $locked->refresh();
            }
            if (!$this->manager->handlerFor($locked)->isComplete(UploadSessionSnapshot::fromModel($locked))) {
                throw UploadConflictException::forClosedSession($locked->upload_id);
            }

            return $this->manager->commitAssembled(session: $locked, commit: fn (UploadSession $assembled): Node => $this->completion->execute(context: $this->context, session: $assembled, deadline: $deadline));
        }));

        return $this->nodes->map($node, $this->context->actor->ref());
    }

    public function abort(UploadSessionSnapshot|int|string $session): void
    {
        $session = $this->find($session);
        $this->write('abort', function () use ($session): void {
            $this->completion->authorizeSession(context: $this->context, session: $session);
            $this->assertResumable();
            $this->abortion->execute(context: $this->context, session: $session);
        });
    }

    /**
     * Protocol adapters use the same current ownership/ACL boundary.
     *
     * @internal
     */
    #[Internal]
    public function status(UploadSessionSnapshot|UploadSession|int|string $session): UploadSession
    {
        $session = $this->find($session);
        $this->completion->authorizeSession(context: $this->context, session: $session);

        return $session;
    }

    /** @internal
     * @param  resource                        $chunk
     * @return array{offset:int,complete:bool}
     */
    #[Internal]
    public function patch(UploadSessionSnapshot|UploadSession|int|string $session, mixed $chunk, int $offset): array
    {
        return $this->manager->withSession($this->find($session), function (UploadSession $locked) use ($chunk, $offset): array {
            $this->completion->authorizeSession(context: $this->context, session: $locked);
            $this->assertResumable();

            if (!in_array($locked->protocol, [UploadProtocol::Tus, UploadProtocol::Direct], true)) {
                throw UploadConflictException::forClosedSession($locked->upload_id);
            }

            return $this->tus->patch(session: $locked, chunk: $chunk, offset: $offset);
        });
    }

    /** @internal
     * @param  resource                                $chunk
     * @return array{part_no:int,size:int,etag:string}
     */
    #[Internal]
    public function putPart(UploadSessionSnapshot|UploadSession|int|string $session, int $partNo, int $byteOffset, mixed $chunk): array
    {
        return $this->manager->withSession($this->find($session), function (UploadSession $locked) use ($partNo, $byteOffset, $chunk): array {
            $this->completion->authorizeSession(context: $this->context, session: $locked);
            $this->assertResumable();

            if ($locked->protocol !== UploadProtocol::S3Multipart) {
                throw UploadConflictException::forClosedSession($locked->upload_id);
            }

            return $this->s3->putPart(session: $locked, partNo: $partNo, byteOffset: $byteOffset, chunk: $chunk);
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    private function write(string $verb, Closure $attempt, ?IdempotentWrite $idempotency = null): mixed
    {
        return $this->runner->write(new OperationSpec('drive.uploads.'.$verb, new ModuleId('drive')), $this->context->operation, $attempt, $idempotency);
    }

    private static function idOf(NodeSnapshot|int|string $key): string
    {
        return $key instanceof NodeSnapshot ? $key->id : (string) $key;
    }

    /**
     * Resumable uploads are a deployment-wide feature checked here rather than by route
     * registration, so a cached route table never freezes it. A session the caller
     * cannot see has already failed as not found.
     */
    private function assertResumable(): void
    {
        if (!$this->config->uploads->resumable->enabled) {
            throw ProfileRestrictionException::forOperation('resumable_upload');
        }
    }

    private function find(UploadSessionSnapshot|UploadSession|int|string $session): UploadSession
    {
        // A snapshot names its session by `id` (never the protocol handle); the current row is what is acted on.
        $key = match (true) {
            $session instanceof UploadSessionSnapshot => $session->id,
            $session instanceof UploadSession => $session->getRawOriginal($session->getKeyName()),
            default => $session,
        };
        if (!is_int($key) && !is_string($key)) {
            throw NodeNotFoundException::forId('upload');
        }
        $fresh = $this->sessions->find($key) ?? throw NodeNotFoundException::forId('upload');
        if ((string) $fresh->drive_id !== (string) $this->context->driveId()) {
            throw NodeNotFoundException::forId('upload');
        }

        return $fresh;
    }
}
