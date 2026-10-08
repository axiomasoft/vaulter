<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Uploads;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vaulter\Contracts\Drive\DriveHandleFactory;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Operations\UploadOperations;
use Vaulter\Drive\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\ValueObjects\Access\Actor;

/**
 * The S3-multipart resumable-upload surface (§2.8 / D16 / M3a). Thin HTTP: it
 * resolves the route-bound session, AUTHORIZES through the AccessResolver (Edit on
 * the node for a new version, Create under the folder for a new file), records each
 * part and, on complete, assembles → BlobStore.commit (server-recompute sha256, H.3 —
 * the multipart ETag is never the content address).
 *
 *   POST uploads/{upload_id}/parts    → record (sign/proxy) a numbered part;
 *   POST uploads/{upload_id}/complete → complete-multipart → assemble → commit.
 *
 * A part re-upload REPLACES (the (session, part_no) composite primary key — S3
 * semantics).
 */
final class S3MultipartProtocolController extends Controller
{
    public function __construct(
        private readonly UploadSessionReadRepository $sessions,
        private readonly DriveReadRepository $storages,
        private readonly DriveHandleFactory $handles,
        private readonly DriveConfig $driveConfig,
        private readonly NodeSnapshotMapper $snapshots,
    ) {}

    /**
     * Record one multipart part. The body is the part bytes; part_no / byte_offset
     * come from the query/body. A re-upload of the same part_no replaces it.
     */
    public function parts(Request $request, string $uploadId): JsonResponse
    {
        $session = $this->findSession($uploadId);
        $this->authorizeSession($request, $session);
        $this->assertContentLengthWithinLimit($request);

        $partNo = $request->has('part_no')
            ? $request->integer('part_no')
            : (int) $request->header('X-Part-Number', '1');
        $byteOffset = $request->integer('byte_offset');

        $result = $this->uploads(request: $request, storage: $this->storageOf($session))->putPart(
            session: $session,
            partNo: $partNo,
            byteOffset: $byteOffset,
            chunk: $this->chunkStream($request),
        );

        return response()->json([
            'part_no' => $result['part_no'],
            'size' => $result['size'],
            'etag' => $result['etag'],
        ], 201);
    }

    /**
     * Complete-multipart: stitch the recorded parts into the assembled staged temp,
     * then assemble → BlobStore.commit (H.3) and return the created node.
     */
    public function complete(Request $request, string $uploadId): JsonResponse
    {
        $session = $this->findSession($uploadId);
        $this->authorizeSession($request, $session);

        $node = $this->uploads(request: $request, storage: $this->storageOf($session))->complete($session);

        return NodeResource::make($this->snapshotOf($node, $request))->response()->setStatusCode(201);
    }

    private function findSession(string $uploadId): UploadSession
    {
        $session = $this->sessions->findByUploadId($uploadId);

        if (!$session instanceof UploadSession) {
            abort(404);
        }

        return $session;
    }

    /**
     * Authorize a request acting on an EXISTING session by its target shape: Edit on
     * the node for a new version, else Create under the destination folder — THEN
     * (F-P1.4-12) confirm the acting actor is the session's OWNER (see
     * TusUploadController::authorizeSession for the full rationale — the two
     * controllers share the identical guard).
     */
    private function authorizeSession(Request $request, UploadSession $session): void
    {
        $this->uploads(request: $request, storage: $this->storageOf($session))->status($session);
    }

    private function uploads(Request $request, Drive $storage): UploadOperations
    {
        return $this->handles->forDrive($storage)->actingAs($this->authorizable($request) ?? throw MissingActorException::required())->extension(UploadOperations::class);
    }

    private function storageOf(UploadSession $session): Drive
    {
        $storage = $this->storages->find($session->drive_id);

        if (!$storage instanceof Drive) {
            abort(404);
        }

        return $storage;
    }

    /**
     * The raw part body as a STREAM, never a PHP string — see
     * TusUploadController::chunkStream() for the full rationale (VLT-REMED P5.2 review /
     * F-P1.4-04): the per-chunk ceiling used to sit BEHIND a `getContent()` that had
     * already materialised the whole body in memory.
     *
     * @return resource
     */
    private function chunkStream(Request $request): mixed
    {
        $stream = $request->getContent(asResource: true);

        if (!is_resource($stream)) {
            abort(500, 'Unable to open a stream for the upload part.');
        }

        return $stream;
    }

    /**
     * Refuse an over-cap part on its DECLARED length, before the body is touched — the
     * S3-multipart twin of TusUploadController::assertContentLengthWithinLimit(). The cap
     * (`uploads.max_chunk_bytes`) covers BOTH protocols: a part body is exactly as
     * unbounded as a PATCH body.
     */
    private function assertContentLengthWithinLimit(Request $request): void
    {
        $max = $this->driveConfig->maxChunkSize();
        $declared = $request->server('CONTENT_LENGTH');

        if ($max <= 0 || !is_numeric($declared) || (int) $declared <= $max) {
            return;
        }

        abort(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, "The upload part exceeds the maximum chunk size of {$max} bytes.");
    }

    private function snapshotOf(Node $node, Request $request): NodeSnapshot
    {
        $user = $this->authorizable($request);

        return $this->snapshots->map($node, $user === null ? null : Actor::user($user)->ref());
    }

    private function authorizable(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
