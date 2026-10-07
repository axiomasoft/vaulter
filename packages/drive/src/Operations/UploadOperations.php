<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Drive\Actions\Uploads\AbortUploadAction;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Actions\Uploads\InitiateUploadAction;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;

final readonly class UploadOperations
{
    public function __construct(
        DriveHandle $handle,
        private OperationContext $context,
        private InitiateUploadAction $initiation,
        private CompleteUploadAction $completion,
        private AbortUploadAction $abortion,
        private UploadSessionManager $manager,
        private UploadSessionReadRepository $sessions,
        private TusUploadHandler $tus,
        private S3MultipartHandler $s3,
    ) {
        $drive = $handle->model();
        $key = $drive->getRawOriginal($drive->getKeyName());
        if ((!is_int($key) && !is_string($key)) || (string) $key !== (string) $context->driveId()) {
            throw NodeNotFoundException::forId('drive');
        }
    }

    public function initiate(InitiateUploadInput $input): UploadSession
    {
        return $this->initiation->execute(context: $this->context, input: $input);
    }

    public function complete(UploadSession|int|string $session): Node
    {
        $session = $this->find($session);

        return $this->manager->withSession($session, function (UploadSession $locked): Node {
            if ($locked->status === UploadStatus::Committed) {
                return $this->completion->execute(context: $this->context, session: $locked);
            }
            $this->completion->authorizeSession(context: $this->context, session: $locked);
            if ($locked->protocol === UploadProtocol::S3Multipart && $locked->status !== UploadStatus::Assembled) {
                $this->s3->complete($locked);
                $locked = $locked->refresh();
            }
            if (!$this->manager->handlerFor($locked)->isComplete($locked)) {
                throw UploadConflictException::forClosedSession($locked->upload_id);
            }

            return $this->manager->commitAssembled(session: $locked, commit: fn (UploadSession $assembled): Node => $this->completion->execute(context: $this->context, session: $assembled));
        });
    }

    public function abort(UploadSession|int|string $session): void
    {
        $this->abortion->execute(context: $this->context, session: $this->find($session));
    }

    /** @internal Protocol adapters use the same current ownership/ACL boundary. */
    public function status(UploadSession|int|string $session): UploadSession
    {
        $session = $this->find($session);
        $this->completion->authorizeSession(context: $this->context, session: $session);

        return $session;
    }

    /** @internal
     * @param  resource                        $chunk
     * @return array{offset:int,complete:bool}
     */
    public function patch(UploadSession|int|string $session, mixed $chunk, int $offset): array
    {
        return $this->manager->withSession($this->find($session), function (UploadSession $locked) use ($chunk, $offset): array {
            $this->completion->authorizeSession(context: $this->context, session: $locked);

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
    public function putPart(UploadSession|int|string $session, int $partNo, int $byteOffset, mixed $chunk): array
    {
        return $this->manager->withSession($this->find($session), function (UploadSession $locked) use ($partNo, $byteOffset, $chunk): array {
            $this->completion->authorizeSession(context: $this->context, session: $locked);

            if ($locked->protocol !== UploadProtocol::S3Multipart) {
                throw UploadConflictException::forClosedSession($locked->upload_id);
            }

            return $this->s3->putPart(session: $locked, partNo: $partNo, byteOffset: $byteOffset, chunk: $chunk);
        });
    }

    private function find(UploadSession|int|string $session): UploadSession
    {
        $key = $session instanceof UploadSession ? $session->getRawOriginal($session->getKeyName()) : $session;
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
