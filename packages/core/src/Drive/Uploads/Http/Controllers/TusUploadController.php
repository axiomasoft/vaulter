<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vaulter\Access\AccessResolver;
use Vaulter\Drive\Http\Resources\NodeResource;
use Vaulter\Drive\Uploads\Actions\CommitUploadAction;
use Vaulter\Drive\Uploads\Data\UploadInitData;
use Vaulter\Drive\Uploads\Enums\UploadProtocol;
use Vaulter\Drive\Uploads\Http\Requests\CreateUploadSessionRequest;
use Vaulter\Drive\Uploads\Http\Resources\UploadSessionResource;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Drive\Uploads\Repositories\UploadSessionReadRepository;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\StorageReadRepository;
use Vaulter\Support\Config;

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
final class TusUploadController extends Controller
{
    private const string TUS_VERSION = '1.0.0';

    public function __construct(
        private readonly UploadSessionManager $manager,
        private readonly UploadSessionReadRepository $sessions,
        private readonly StorageReadRepository $storages,
        private readonly NodeReadRepository $nodes,
        private readonly AccessResolver $access,
        private readonly CommitUploadAction $commit,
    ) {}

    /**
     * Create a resumable session (tus Creation). Authorizes the target (Create under
     * the folder for a new file, Edit on the node for a new version), opens the
     * session and returns 201 with the tus Location header (the upload_id).
     */
    public function create(CreateUploadSessionRequest $request, Storage $storage): JsonResponse
    {
        $this->assertTusEnabled();

        $target = $this->resolveAndAuthorizeTarget(
            request: $request,
            storage: $storage,
            targetNodeId: $request->targetNodeId(),
            parentId: $request->parentId(),
        );

        $session = $this->manager->initiate(new UploadInitData(
            storage: $storage,
            protocol: UploadProtocol::Tus,
            targetParent: $target['parent'],
            targetName: $request->name(),
            targetNode: $target['node'],
            expectedSha256: $request->expectedSha256(),
            totalSizeBytes: $request->totalSizeBytes(),
            mimeType: $request->mimeType(),
            metadata: $this->mergedMetadata($request),
            actorId: $this->actorId($request),
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

        $offset = (int) $request->header('Upload-Offset', '0');

        $result = $this->tusHandler()->patch(
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

        // All bytes in: assemble→commit (the SAME race-free CAS path, H.3).
        $session = $this->manager->markAssembled($session->refresh());
        $node = $this->commit->execute($session->refresh());

        return NodeResource::make($node)
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

        $this->manager->abort($session);

        return response()->noContent(headers: ['Tus-Resumable' => self::TUS_VERSION]);
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

    /**
     * Resolve + authorize the create target (§2.8). A new-version upload (node_id)
     * needs Edit on the node; a new-file upload needs Create under the destination
     * folder (and the workspace-create gate). Returns the resolved parent/node pair.
     *
     * @return array{parent: ?Node, node: ?Node}
     */
    private function resolveAndAuthorizeTarget(
        Request $request,
        Storage $storage,
        int|string|null $targetNodeId,
        int|string|null $parentId,
    ): array {
        if ($targetNodeId !== null) {
            $node = $this->findNodeInStorage($targetNodeId, $storage);
            $this->guard($request, Ability::Edit, $node);

            return ['parent' => null, 'node' => $node];
        }

        $parent = $parentId === null
            ? $this->rootOf($storage)
            : $this->findNodeInStorage($parentId, $storage);

        $this->guardCreate($request, $storage, $parent);

        return ['parent' => $parent, 'node' => null];
    }

    /**
     * Authorize a request acting on an EXISTING session by its target shape: Edit on
     * the node for a new version, else Create under the destination folder.
     */
    private function authorizeSession(Request $request, UploadSession $session): void
    {
        $storage = $this->storageOf($session);

        if ($session->isNewVersion()) {
            $node = $this->findNodeInStorage($session->node_id ?? '', $storage);
            $this->guard($request, Ability::Edit, $node);

            return;
        }

        $parent = $session->target_parent_id === null
            ? $this->rootOf($storage)
            : $this->findNodeInStorage($session->target_parent_id, $storage);

        $this->guardCreate($request, $storage, $parent);
    }

    private function storageOf(UploadSession $session): Storage
    {
        $storage = $this->storages->find($session->storage_id);

        if (!$storage instanceof Storage) {
            abort(404);
        }

        return $storage;
    }

    private function rootOf(Storage $storage): Node
    {
        $root = $storage->rootNode()->first();

        if (!$root instanceof Node) {
            abort(404);
        }

        return $root;
    }

    private function findNodeInStorage(int|string $nodeId, Storage $storage): Node
    {
        $node = $this->nodes->find($nodeId);

        if (!$node instanceof Node || $node->storage_id !== $storage->getKey()) {
            abort(404);
        }

        return $node;
    }

    private function tusHandler(): TusUploadHandler
    {
        return app(TusUploadHandler::class);
    }

    /**
     * The raw PATCH body as a stream the handler appends.
     *
     * @return resource
     */
    private function chunkStream(Request $request): mixed
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            abort(500, 'Unable to open a stream for the upload chunk.');
        }

        fwrite($stream, $request->getContent());
        rewind($stream);

        return $stream;
    }

    /**
     * Merge the tus Upload-Metadata header (base64-encoded key value pairs) over the
     * JSON body metadata, so both true tus clients and the JSON API are supported.
     *
     * @return array<string, string>
     */
    private function mergedMetadata(CreateUploadSessionRequest $request): array
    {
        return [...$request->metadata(), ...$this->decodeUploadMetadata($request)];
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

            $metadata[$key] = isset($segments[1]) ? (string) base64_decode($segments[1], strict: false) : '';
        }

        return $metadata;
    }

    private function locationFor(Request $request, UploadSession $session): string
    {
        return rtrim($request->getSchemeAndHttpHost().'/'.Config::routePrefix(), '/')."/uploads/{$session->upload_id}";
    }

    private function assertTusEnabled(): void
    {
        if (!Config::tusEnabled()) {
            abort(403, 'The tus resumable-upload protocol is disabled.');
        }
    }

    /**
     * Authorize an ability on a node through the AccessResolver (H.2); 403 on denial.
     */
    private function guard(Request $request, Ability $ability, Node $node): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null || !$this->access->can($actor, $ability, $node)) {
            throw PermissionDeniedException::forAbility($ability->value);
        }
    }

    private function guardCreate(Request $request, Storage $storage, Node $destination): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null
            || !$this->access->canCreateInWorkspace($actor, $storage)
            || !$this->access->can($actor, Ability::Create, $destination)
        ) {
            throw PermissionDeniedException::forAbility(Ability::Create->value);
        }
    }

    private function authorizable(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
