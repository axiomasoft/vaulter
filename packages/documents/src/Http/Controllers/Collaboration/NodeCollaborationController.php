<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Collaboration;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Documents\Collaboration\CollaborationHub;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Middleware\ResolveCollaborationCall;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;
use Vaulter\VaulterManager;

/**
 * The realtime-collaboration HTTP surface for document nodes:
 *
 *   - token()        — the client's connect handshake through
 *                      DocumentOperations::collaborationSession(): View to connect,
 *                      read-only unless the room may write.
 *   - authenticate() — the hub's onAuthenticate: is the connection allowed, and in which
 *                      mode, right now.
 *   - load()         — the hub's onLoadDocument: the Yjs working state, or the JSON to
 *                      seed it from once.
 *   - store()        — the hub's onStoreDocument (and its seed): persist the room's state
 *                      behind the epoch fence.
 *
 * The hub calls carry a room JWT and a body signature (ResolveCollaborationCall) and go
 * through the internal CollaborationHub, which re-checks the live document and rights
 * on every call. No model queries here.
 */
final class NodeCollaborationController extends Controller
{
    public function __construct(
        private readonly VaulterManager $vaulter,
        private readonly DocumentsManager $documents,
        private readonly CollaborationHub $hub,
    ) {}

    /**
     * Issue a collaboration session for the route-bound document node. A user without
     * View gets 404. The body may name the `schema_version` the client's editor
     * supports; a different one gets a read-only session.
     */
    public function token(Request $request, Node $node): JsonResponse
    {
        $actor = $this->authorizable($request) ?? throw MissingActorException::required();
        $drive = $node->storage()->first() ?? throw NodeNotFoundException::forId(Identity::stringify($node->getKey()));
        $schemaVersion = $request->input('schema_version');

        $session = $this->documents
            ->in($this->vaulter->drive(ownerOrDrive: $drive)->actingAs($actor))
            ->collaborationSession(Identity::stringify($node->getKey()), is_int($schemaVersion) ? $schemaVersion : null);

        return new JsonResponse(['data' => $session->toArray()]);
    }

    /**
     * The hub's onAuthenticate: allowed=false (rather than a 403) when the user no
     * longer holds View, so the hub refuses the socket cleanly. A room of a past
     * generation answers 409 collaboration_epoch_mismatch and the client asks for a
     * new session.
     */
    public function authenticate(Request $request): JsonResponse
    {
        $call = ResolveCollaborationCall::call($request);
        $room = $this->hub->open($call, $request->input('room'));

        if ($room->ability === null) {
            return new JsonResponse(['allowed' => false, 'mode' => 'ro', 'userId' => null]);
        }

        return new JsonResponse([
            'allowed' => true,
            'mode' => $room->mode(),
            'userId' => $room->userId,
            'epoch' => $room->document->collab_epoch,
        ]);
    }

    /** The hub's onLoadDocument. */
    public function load(Request $request): JsonResponse
    {
        $call = ResolveCollaborationCall::call($request);

        return new JsonResponse($this->hub->load($this->hub->open($call, $request->input('room'))));
    }

    /**
     * The hub's onStoreDocument: {room, state (base64 Yjs), content (JSON projection),
     * seed}. A read-only token or room is 403; a room of a past generation, or a seed
     * over an existing state, is 409 and the hub closes the room without retrying.
     */
    public function store(Request $request): JsonResponse
    {
        $call = ResolveCollaborationCall::call($request);
        $room = $this->hub->open($call, $request->input('room'));
        $content = $request->input('content');
        // The projection is a JSON object (a document node): only its string keys are content.
        $content = is_array($content) ? array_filter($content, is_string(...), ARRAY_FILTER_USE_KEY) : null;

        $result = $this->hub->store(
            room: $room,
            call: $call,
            yjsState: $this->yjsState($request),
            content: $content,
            seed: $request->boolean('seed'),
        );

        return new JsonResponse([
            'data' => [
                'changed' => $result->changed,
                'epoch' => $result->document->collab_epoch,
                'state_version' => $result->document->state_version,
                'yjs_state_size' => $result->document->yjs_state_size,
            ],
        ]);
    }

    /**
     * The raw binary Yjs state from the base64 `state` field. The Yjs binary is the
     * source of truth; an absent/empty/invalid state is a 422 (it is never rebuilt
     * from the JSON here).
     */
    private function yjsState(Request $request): string
    {
        $encoded = $request->input('state');

        if (!is_string($encoded) || $encoded === '') {
            abort(422, 'A collaboration store call must carry a base64 state.');
        }

        $decoded = base64_decode($encoded, strict: true);

        if ($decoded === false || $decoded === '') {
            abort(422, 'The state must be valid base64-encoded binary.');
        }

        return $decoded;
    }

    private function authorizable(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
