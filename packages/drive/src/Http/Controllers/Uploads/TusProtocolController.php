<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Uploads;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Contracts\Drive\DriveHandleFactory;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Http\Requests\Uploads\CreateUploadSessionRequest;
use Vaulter\Drive\Http\Resources\Uploads\UploadSessionResource;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Operations\UploadOperations;
use Vaulter\Drive\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Resources\Drive\LegacyNodeResource as NodeResource;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\ValueObjects\Access\Actor;

/**
 * The tus.io CORE resumable-upload surface (§2.8 / D16 / M3a). Thin HTTP: it resolves
 * the route-bound storage / session, AUTHORIZES through the AccessResolver
 * (Ability::Create in the target folder for a new file; Ability::Edit on the node for
 * a new version), and delegates every byte/state move to the UploadSessionManager /
 * TusUploadHandler / CommitUploadAction. No model writes live here.
 *
 *   POST   uploads               → create a session (returns Location + upload_id);
 *   HEAD   uploads/{upload_id}    → the authoritative Upload-Offset (resume point);
 *   PATCH  uploads/{upload_id}    → append a chunk; on completion assemble→commit;
 *   DELETE uploads/{upload_id}    → abort (staged temp + multipart reclaimed);
 *   GET    uploads/{upload_id}    → the session status (poll).
 *
 * The tus headers (Tus-Resumable, Upload-Offset, Upload-Length, Upload-Metadata) are
 * set per the protocol. H.3: the assembled bytes go through the SAME race-free
 * BlobStore.commit — the client sha256 / S3 ETag is never trusted.
 */
final class TusProtocolController extends Controller
{
    private const string TUS_VERSION = '1.0.0';

    public function __construct(
        private readonly UploadSessionReadRepository $sessions,
        private readonly DriveReadRepository $storages,
        private readonly DriveHandleFactory $handles,
        private readonly DriveConfig $driveConfig,
        private readonly RoutesConfig $routesConfig,
        private readonly NodeSnapshotMapper $snapshots,
    ) {}

    /**
     * Create a resumable session (tus Creation). Authorizes the target (Create under
     * the folder for a new file, Edit on the node for a new version), opens the
     * session and returns 201 with the tus Location header (the upload_id).
     */
    public function create(CreateUploadSessionRequest $request, Drive $storage): JsonResponse
    {
        $this->assertTusEnabled();

        $session = $this->uploads(request: $request, storage: $storage)->initiate(new InitiateUploadInput(
            protocol: $request->protocol(), targetParent: $request->parentId(), targetName: $request->name(),
            targetNode: $request->targetNodeId(), expectedSha256: $request->expectedSha256(),
            totalSizeBytes: $request->totalSizeBytes(), mimeType: $request->mimeType(), metadata: $this->mergedMetadata($request),
        ));

        return UploadSessionResource::make($session)
            ->response()
            ->setStatusCode(201)
            ->withHeaders([
                'Tus-Resumable' => self::TUS_VERSION,
                'Location' => $this->locationFor($request, $session),
                'Upload-Offset' => '0',
            ]);
    }

    /**
     * The tus HEAD: return the authoritative Upload-Offset (the server-side resume
     * point) plus Upload-Length. A second client resuming reads THIS, never its own
     * guess.
     */
    public function head(Request $request, string $uploadId): Response
    {
        $this->assertTusEnabled();

        $session = $this->findSession($uploadId);
        $this->authorizeSession($request, $session);
        $this->assertTusResumableHeader($request);

        $offset = $this->tusHandler()->offset($session);

        $headers = [
            'Tus-Resumable' => self::TUS_VERSION,
            'Upload-Offset' => (string) $offset,
            'Cache-Control' => 'no-store',
        ];

        if ($session->total_size_bytes !== null) {
            $headers['Upload-Length'] = (string) $session->total_size_bytes;
        }

        return response()->noContent(headers: $headers);
    }

    /**
     * The tus PATCH: append the application/offset+octet-stream chunk at the declared
     * Upload-Offset, advance the offset, and — when the upload is complete — assemble
     * the staged bytes through BlobStore.commit (H.3) and return the created node.
     */
    public function patch(Request $request, string $uploadId): JsonResponse|Response
    {
        $this->assertTusEnabled();

        $session = $this->findSession($uploadId);
        $this->authorizeSession($request, $session);
        $this->assertTusResumableHeader($request);
        $this->assertOffsetOctetStreamContentType($request);
        $this->assertContentLengthWithinLimit($request);

        $offset = (int) $request->header('Upload-Offset', '0');

        $result = $this->uploads(request: $request, storage: $this->storageOf($session))->patch(
            session: $session,
            chunk: $this->chunkStream($request),
            offset: $offset,
        );

        if (!$result['complete']) {
            return response()->noContent(headers: [
                'Tus-Resumable' => self::TUS_VERSION,
                'Upload-Offset' => (string) $result['offset'],
            ]);
        }

        // All bytes in: assemble→commit (the SAME race-free CAS path, H.3) — driven through
        // the manager so it runs INSIDE the session's critical section (R-11): a sweeper
        // may legally reclaim an Assembled session, and out here it used to be free to
        // discard the staged bytes mid-commit.
        $node = $this->uploads(request: $request, storage: $this->storageOf($session))->complete($session);

        return NodeResource::make($this->snapshotOf($node, $request))
            ->response()
            ->setStatusCode(201)
            ->withHeaders([
                'Tus-Resumable' => self::TUS_VERSION,
                'Upload-Offset' => (string) $result['offset'],
            ]);
    }

    /**
     * The tus DELETE: abort the session (staged temp + any multipart reclaimed).
     */
    public function abort(Request $request, string $uploadId): Response
    {
        $this->assertTusEnabled();

        $session = $this->findSession($uploadId);
        $this->authorizeSession($request, $session);
        $this->assertTusResumableHeader($request);

        $this->uploads(request: $request, storage: $this->storageOf($session))->abort($session);

        return response()->noContent(headers: ['Tus-Resumable' => self::TUS_VERSION]);
    }

    /**
     * The tus OPTIONS discovery (§3.1 / F-P1.4-07): the CORE protocol requires this
     * to be answerable WITHOUT authorization or a resolved session — it advertises
     * the resumable-upload version, the extensions this deployment actually
     * implements (creation, termination — checksum is NOT implemented and therefore
     * deliberately NOT listed, U1.6) and the max upload size (omitted when
     * unbounded, per the spec's "MAY").
     */
    public function options(): Response
    {
        $this->assertTusEnabled();

        $headers = [
            'Tus-Resumable' => self::TUS_VERSION,
            'Tus-Version' => self::TUS_VERSION,
            'Tus-Extension' => 'creation,termination',
        ];

        $maxBytes = $this->driveConfig->maxFileSize();

        if ($maxBytes > 0) {
            $headers['Tus-Max-Size'] = (string) $maxBytes;
        }

        return response()->noContent(headers: $headers);
    }

    /**
     * A JSON status poll for the session (not part of the tus core — the client UX).
     */
    public function status(Request $request, string $uploadId): UploadSessionResource
    {
        $session = $this->findSession($uploadId);
        $this->authorizeSession($request, $session);

        return UploadSessionResource::make($session);
    }

    private function findSession(string $uploadId): UploadSession
    {
        $session = $this->sessions->findByUploadId($uploadId);

        if (!$session instanceof UploadSession) {
            abort(404);
        }

        return $session;
    }

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

    private function tusHandler(): TusUploadHandler
    {
        return app(TusUploadHandler::class);
    }

    /**
     * The raw PATCH body as a STREAM the handler appends — `php://input` itself, never a
     * PHP string (VLT-REMED P5.2 review / F-P1.4-04).
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
     * (VLT-REMED P5.2 review): a client that announces more than `uploads.max_chunk_bytes`
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
     * Merge the tus Upload-Metadata header (base64-encoded key value pairs) over the
     * JSON body metadata, so both true tus clients and the JSON API are supported,
     * then whitelist keys + cap value length (F-P1.4-08) — the merged map is what
     * lands in v_upload_sessions.metadata verbatim otherwise.
     *
     * @return array<string, string>
     */
    private function mergedMetadata(CreateUploadSessionRequest $request): array
    {
        $merged = [...$request->metadata(), ...$this->decodeUploadMetadata($request)];

        return $this->sanitizeMetadata($merged);
    }

    /**
     * F-P1.4-08: drop any key outside the configured whitelist and truncate an
     * over-long value — an unbounded key set / value size otherwise grows
     * v_upload_sessions.metadata without limit (U4.3/U4.4). Unknown keys are
     * SILENTLY dropped, not rejected: a tus client sending one extra vendor key
     * must not fail the whole upload over it.
     *
     * @param  array<string, string> $metadata
     * @return array<string, string>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $allowedKeys = $this->driveConfig->metadataAllowedKeys();
        $maxLength = $this->driveConfig->metadataMaxValueLength();

        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (!in_array($key, $allowedKeys, true)) {
                continue;
            }

            $sanitized[$key] = mb_substr((string) $value, 0, $maxLength);
        }

        return $sanitized;
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

    private function locationFor(Request $request, UploadSession $session): string
    {
        return rtrim($request->getSchemeAndHttpHost().'/'.$this->routesConfig->prefix(), '/').('/uploads/'.$session->upload_id);
    }

    private function assertTusEnabled(): void
    {
        if (!$this->driveConfig->resumableEnabled()) {
            abort(403, 'The tus resumable-upload protocol is disabled.');
        }
    }

    /**
     * F-P1.4-06: the CORE protocol requires the client to declare the resumable
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
     * F-P1.4-05: a tus PATCH body MUST be application/offset+octet-stream; anything
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

    /**
     * Authorize an ability on a node through the AccessResolver (H.2); 403 on denial.
     */
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
