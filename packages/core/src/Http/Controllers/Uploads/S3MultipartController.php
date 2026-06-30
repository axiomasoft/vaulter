<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Uploads;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Access\AccessResolver;
use Vaulter\Actions\Uploads\CommitUploadAction;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Models\Uploads\UploadSession;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\StorageReadRepository;
use Vaulter\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Uploads\S3MultipartHandler;
use Vaulter\Uploads\UploadSessionManager;

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
final class S3MultipartController extends Controller
{
    public function __construct(
        private readonly UploadSessionManager $manager,
        private readonly UploadSessionReadRepository $sessions,
        private readonly StorageReadRepository $storages,
        private readonly NodeReadRepository $nodes,
        private readonly AccessResolver $access,
        private readonly CommitUploadAction $commit,
    ) {}

    /**
     * Record one multipart part. The body is the part bytes; part_no / byte_offset
     * come from the query/body. A re-upload of the same part_no replaces it.
     */
    public function parts(Request $request, string $uploadId): JsonResponse
    {
        $session = $this->findSession($uploadId);
        $this->authorizeSession($request, $session);

        $partNo = $request->has('part_no')
            ? $request->integer('part_no')
            : (int) $request->header('X-Part-Number', '1');
        $byteOffset = $request->integer('byte_offset');

        $result = $this->s3Handler()->putPart(
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

        $this->s3Handler()->complete($session);

        $session = $this->manager->markAssembled($session->refresh());
        $node = $this->commit->execute($session->refresh());

        return NodeResource::make($node)->response()->setStatusCode(201);
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

    private function s3Handler(): S3MultipartHandler
    {
        return app(S3MultipartHandler::class);
    }

    /**
     * @return resource
     */
    private function chunkStream(Request $request): mixed
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            abort(500, 'Unable to open a stream for the upload part.');
        }

        fwrite($stream, $request->getContent());
        rewind($stream);

        return $stream;
    }

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
