<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Uploads;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Http\Requests\Uploads\CreateUploadSessionRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Drive\Http\Resources\Uploads\UploadSessionResource;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadsOf;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/**
 * The tus.io CORE resumable-upload protocol over the upload operations, which authorize
 * every act (Create in the target folder for a new file, Edit on the file for a new
 * revision, and only the session's owner afterwards) and move every byte:
 *
 *   POST    drives/{drive}/uploads  → create a session (through CreateUploadController);
 *   HEAD    uploads/{upload}        → the authoritative Upload-Offset (resume point);
 *   PATCH   uploads/{upload}        → append a chunk; the last one commits the file;
 *   DELETE  uploads/{upload}        → abort (staged bytes reclaimed);
 *   GET     uploads/{upload}        → the session status (poll);
 *   OPTIONS uploads/{upload}        → protocol discovery.
 *
 * The protocol keeps its own acts and headers (Tus-Resumable, Upload-Offset,
 * Upload-Length). The assembled bytes go through the same race-free blob commit as any
 * upload; a client checksum is never trusted as the content address.
 *
 * A session is found by its upload id only to learn which drive it belongs to; that
 * drive is resolved tenant-scoped and the operations then decide, so an unknown,
 * foreign or someone else's session all answer not found.
 */
final class TusProtocolController
{
    private const string TUS_VERSION = '1.0.0';

    public function __construct(
        private readonly UploadSessionReadRepository $sessions,
        private readonly HttpResourceResolver $resolver,
        private readonly HttpOperationContextFactory $contexts,
        private readonly NodeResponses $nodes,
        private readonly NodeSnapshotMapper $snapshots,
        private readonly DriveConfig $driveConfig,
        private readonly RoutesConfig $routesConfig,
    ) {}

    /** Create a session (tus Creation): 201 with the tus Location of the session. */
    public function create(CreateUploadSessionRequest $request, DriveHandle $drive): JsonResponse
    {
        $session = UploadsOf::handle($drive)->initiate(new InitiateUploadInput(
            protocol: $request->protocol(), targetParent: $request->parentId(), targetName: $request->name(),
            targetNode: $request->targetNodeId(), expectedSha256: $request->expectedSha256(),
            totalSizeBytes: $request->totalSizeBytes(), mimeType: $request->mimeType(), metadata: $request->allowedMetadata($this->driveConfig, $this->decodeUploadMetadata($request)),
        ));

        return (new UploadSessionResource($session))
            ->response()
            ->setStatusCode(201)
            ->withHeaders([
                'Tus-Resumable' => self::TUS_VERSION,
                'Location' => $this->locationFor($request, $session),
                'Upload-Offset' => '0',
            ]);
    }

    /** The tus HEAD: the server-side resume point and the declared length. */
    public function head(Request $request, string $upload): Response
    {
        [, $session] = $this->session($request, $upload);
        $this->assertResumable();
        $this->assertTusResumableHeader($request);

        $headers = [
            'Tus-Resumable' => self::TUS_VERSION,
            'Upload-Offset' => (string) app(TusUploadHandler::class)->offset($session),
        ];
        if ($session->total_size_bytes !== null) {
            $headers['Upload-Length'] = (string) $session->total_size_bytes;
        }

        return new Response(status: 204, headers: $headers);
    }

    /**
     * The tus PATCH: append the chunk at the declared Upload-Offset; once every byte is
     * in, commit the file inside the session's critical section and answer with the node.
     */
    public function patch(Request $request, string $upload): JsonResponse|Response
    {
        [$drive, $session] = $this->session($request, $upload);
        $this->assertTusResumableHeader($request);
        $this->assertOffsetOctetStreamContentType($request);
        $this->assertContentLengthWithinLimit($request);

        $uploads = UploadsOf::handle($drive);
        $result = $uploads->patch(session: $session, chunk: $this->chunkStream($request), offset: (int) $request->header('Upload-Offset', '0'));

        if (!$result['complete']) {
            return new Response(status: 204, headers: [
                'Tus-Resumable' => self::TUS_VERSION,
                'Upload-Offset' => (string) $result['offset'],
            ]);
        }

        $node = $this->snapshots->map($uploads->complete($session), $drive->actor()->ref());

        return $this->nodes->respond($drive, $node, 201)->withHeaders([
            'Tus-Resumable' => self::TUS_VERSION,
            'Upload-Offset' => (string) $result['offset'],
        ]);
    }

    /** The tus DELETE: abort the session and reclaim its staged bytes. */
    public function abort(Request $request, string $upload): Response
    {
        [$drive, $session] = $this->session($request, $upload);
        $this->assertTusResumableHeader($request);
        UploadsOf::handle($drive)->abort($session);

        return new Response(status: 204, headers: ['Tus-Resumable' => self::TUS_VERSION]);
    }

    /**
     * The tus OPTIONS discovery, answerable without resolving a session: the version, the
     * extensions this deployment implements (creation, termination; checksum is not
     * implemented and so not listed) and the size cap when there is one.
     */
    public function options(): Response
    {
        $this->assertResumable();
        $headers = [
            'Tus-Resumable' => self::TUS_VERSION,
            'Tus-Version' => self::TUS_VERSION,
            'Tus-Extension' => 'creation,termination',
        ];
        $maxBytes = $this->driveConfig->maxFileSize();
        if ($maxBytes > 0) {
            $headers['Tus-Max-Size'] = (string) $maxBytes;
        }

        return new Response(status: 204, headers: $headers);
    }

    /** A JSON status poll (not part of tus core). */
    public function status(Request $request, string $upload): UploadSessionResource
    {
        [, $session] = $this->session($request, $upload);

        return new UploadSessionResource($session);
    }

    /**
     * The caller's view of a session: its drive as the caller's handle and the session
     * after the operations checked that the caller may act on it.
     *
     * @return array{DriveHandle, UploadSession}
     */
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
     * The raw PATCH body as a STREAM the handler appends — `php://input` itself, never a
     * PHP string (review /).
     *
     * `fwrite($stream, $request->getContent())` materialised the ENTIRE body in memory
     * before the per-chunk ceiling downstream ever got to look at it: the barrier stood
     * behind the place the harm was delivered, so one authorized PATCH the size of
     * `uploads.max_bytes` (5 GiB by default) could take the worker out on memory. Handing
     * the resource straight through keeps peak memory at the copy buffer, whatever the
     * client sends — and {@see assertContentLengthWithinLimit()} refuses the obvious case
     * before a byte is read at all.
     *
     * @return resource
     */
    private function chunkStream(Request $request): mixed
    {
        $stream = $request->getContent(asResource: true);

        if (!is_resource($stream)) {
            abort(500, 'Unable to open a stream for the upload chunk.');
        }

        return $stream;
    }

    /**
     * Refuse an over-cap PATCH on its DECLARED length, before the body is touched
     * (review): a client that announces more than `uploads.max_chunk_bytes`
     * gets a 413 and we read nothing. A body with no declared length is not trusted to be
     * small — StagedUpload cuts THAT one off at the ceiling mid-copy, before staging.
     */
    private function assertContentLengthWithinLimit(Request $request): void
    {
        $max = $this->driveConfig->maxChunkSize();
        $declared = $request->server('CONTENT_LENGTH');

        if ($max <= 0 || !is_numeric($declared) || (int) $declared <= $max) {
            return;
        }

        abort(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, sprintf('The upload chunk exceeds the maximum chunk size of %d bytes.', $max), [
            'Tus-Resumable' => self::TUS_VERSION,
        ]);
    }

    /**
     * Decode the tus Upload-Metadata header: a comma-separated list of `key
     * base64(value)` pairs (a bare key is a present-but-empty value).
     *
     * @return array<string, string>
     */
    private function decodeUploadMetadata(Request $request): array
    {
        $header = $request->header('Upload-Metadata');

        if (!is_string($header) || $header === '') {
            return [];
        }

        $metadata = [];

        foreach (explode(',', $header) as $pair) {
            $segments = explode(' ', trim($pair), 2);
            $key = $segments[0];

            if ($key === '') {
                continue;
            }

            $metadata[$key] = isset($segments[1]) ? base64_decode($segments[1], strict: false) : '';
        }

        return $metadata;
    }

    /**
     * The protocol acts other than the poll are refused while resumable uploads are off;
     * the operations refuse writing ones the same way.
     */
    private function assertResumable(): void
    {
        if (!$this->driveConfig->resumableEnabled()) {
            throw ProfileRestrictionException::forOperation('resumable_upload');
        }
    }

    private function locationFor(Request $request, UploadSession $session): string
    {
        return $request->getSchemeAndHttpHost().'/'.$this->routesConfig->versionedPrefix().'/uploads/'.$session->upload_id;
    }

    /**
     * the CORE protocol requires the client to declare the resumable
     * version it speaks; a request that omits it (or names one this deployment does
     * not support) is refused with 412 + the version we DO support, never silently
     * accepted.
     */
    private function assertTusResumableHeader(Request $request): void
    {
        if ($request->header('Tus-Resumable') === self::TUS_VERSION) {
            return;
        }

        abort(Response::HTTP_PRECONDITION_FAILED, 'Unsupported or missing Tus-Resumable version.', [
            'Tus-Resumable' => self::TUS_VERSION,
            'Tus-Version' => self::TUS_VERSION,
        ]);
    }

    /**
     * a tus PATCH body MUST be application/offset+octet-stream; anything
     * else (a stray text/plain, a misconfigured client) is refused with 415 BEFORE
     * a single byte is appended — never silently accepted as a valid chunk.
     */
    private function assertOffsetOctetStreamContentType(Request $request): void
    {
        $contentType = (string) $request->header('Content-Type', '');

        if (str_starts_with($contentType, 'application/offset+octet-stream')) {
            return;
        }

        abort(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'PATCH requires Content-Type: application/offset+octet-stream.', [
            'Tus-Resumable' => self::TUS_VERSION,
        ]);
    }
}
