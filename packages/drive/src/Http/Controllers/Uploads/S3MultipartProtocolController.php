<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Uploads;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Packman\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Snapshots\UploadSessionSnapshot;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Drive\Http\Requests\Uploads\CreateUploadSessionRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Drive\Http\Resources\Uploads\UploadSessionResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Drive\Uploads\DirectMultipartUpload;
use Vaulter\Drive\Uploads\UploadsOf;

/**
 * The S3-multipart resumable-upload protocol over the upload operations:
 *
 *   POST drives/{drive}/uploads     → create a session (through CreateUploadController);
 *   POST uploads/{upload}/parts     → store a numbered part (a re-upload replaces it);
 *   POST uploads/{upload}/complete  → stitch the parts and commit the file.
 *
 * The committed content address is recomputed from the assembled bytes; a part's ETag
 * is only the protocol's receipt. Sessions are resolved like the tus ones: by upload id
 * to their drive, tenant-scoped, then authorized by the operations.
 */
#[Internal]
final class S3MultipartProtocolController
{
    public function __construct(
        private readonly UploadSessionReadRepository $sessions,
        private readonly HttpResourceResolver $resolver,
        private readonly HttpOperationContextFactory $contexts,
        private readonly NodeResponses $nodes,
        private readonly DriveOptions $driveConfig,
    ) {}

    public function create(CreateUploadSessionRequest $request, DriveHandle $drive, ?int $staleSequence = null): JsonResponse
    {
        $session = UploadsOf::handle($drive)->initiate(new InitiateUploadInput(
            protocol: UploadProtocol::S3Multipart, targetParent: $request->parentId(), targetName: $request->name(),
            targetNode: $request->targetNodeId(), expectedSha256: $request->expectedSha256(),
            totalSizeBytes: $request->totalSizeBytes(), mimeType: $request->mimeType(), metadata: $request->allowedMetadata($this->driveConfig), expectedSequence: $staleSequence,
        ));

        return new UploadSessionResource($session)->response()->setStatusCode(201);
    }

    /**
     * Store one part. The body is the part bytes; part_no comes from the query or body,
     * or the X-Part-Number header.
     */
    public function parts(Request $request, string $upload): JsonResponse
    {
        [$drive, $session] = $this->session($request, $upload);
        $uploads = UploadsOf::handle($drive);
        if (DirectMultipartUpload::active($session)) {
            $part = $request->query('part_no', $request->header('X-Part-Number', '1'));
            $direct = $uploads->presignMultipartPart($session, is_numeric($part) && ctype_digit($part) ? (int) $part : 0);

            return new JsonResponse($direct, 201, ['Cache-Control' => 'private, no-store']);
        }
        $this->assertContentLengthWithinLimit($request);

        $partNo = $request->has('part_no')
            ? $request->integer('part_no')
            : (int) $request->header('X-Part-Number', '1');

        $result = $uploads->putPart(
            session: $session,
            partNo: $partNo,
            byteOffset: $request->integer('byte_offset'),
            chunk: $this->chunkStream($request),
        );

        return new JsonResponse([
            'part_no' => $result['part_no'],
            'size' => $result['size'],
            'etag' => $result['etag'],
        ], 201);
    }

    /** Complete the multipart upload: stitch the parts, commit, answer with the node. */
    public function complete(Request $request, string $upload): JsonResponse
    {
        [$drive, $session] = $this->session($request, $upload);
        $uploads = UploadsOf::handle($drive);
        if ($uploads->prepareDirectMultipart($session)) {
            return new JsonResponse(['status' => 'pending_scan'], 202, ['Retry-After' => '2', 'Cache-Control' => 'private, no-store']);
        }
        $node = $uploads->complete(UploadSessionSnapshot::fromModel($session));

        return $this->nodes->respond($drive, $node, 201);
    }

    /** @return array{DriveHandle, UploadSession} */
    private function session(Request $request, string $upload): array
    {
        $found = $this->sessions->findByUploadId($upload) ?? throw NodeNotFoundException::forId('upload');
        try {
            $drive = $this->contexts->forDrive($request, $this->resolver->drive((string) $found->drive_id));
        } catch (StorageNotFoundException) {
            throw NodeNotFoundException::forId('upload');
        }

        return [$drive, UploadsOf::handle($drive)->status($found)];
    }

    /**
     * The raw part body as a STREAM, never a PHP string — see
     * TusProtocolController::chunkStream() for the full rationale (review /
     * ): the per-chunk ceiling used to sit BEHIND a `getContent()` that had
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
     * S3-multipart twin of TusProtocolController::assertContentLengthWithinLimit(). The cap
     * (`uploads.max_chunk_bytes`) covers BOTH protocols: a part body is exactly as
     * unbounded as a PATCH body.
     */
    private function assertContentLengthWithinLimit(Request $request): void
    {
        $max = $this->driveConfig->uploads->resumable->maxChunkSize;
        $declared = $request->server('CONTENT_LENGTH');

        if ($max <= 0 || !is_numeric($declared) || (int) $declared <= $max) {
            return;
        }

        abort(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, "The upload part exceeds the maximum chunk size of {$max} bytes.");
    }
}
