<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Access\AccessResolver;
use Vaulter\Documents\Actions\PersistYjsSnapshotAction;
use Vaulter\Documents\Collaboration\CollaborationCall;
use Vaulter\Documents\Contracts\Collaboration\HostUserResolver;
use Vaulter\Documents\Data\PersistYjsSnapshotData;
use Vaulter\Documents\Documents\CollaborationSessionService;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Documents\Http\Middleware\ResolveCollaborationCall;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Support\Config;
use Vaulter\ValueObjects\Documents\TipTapContent;

/**
 * The M4b realtime-collaboration HTTP surface for document nodes (master plan §7 /
 * ADR-0001). Three thin endpoints:
 *
 *   - token()        — the client-facing connect handshake. Authorizes via the
 *                      CollaborationSessionService (≥ View/Comment to connect,
 *                      read-only unless Edit) and returns the transport-agnostic
 *                      CollaborationSessionData descriptor.
 *   - authenticate() — the server-to-server hook the Node hub's onAuthenticate calls.
 *                      The call is already authenticated by ResolveCollaborationCall;
 *                      this resolves the user's ability on the node and answers
 *                      {allowed, readOnly, user}.
 *   - store()        — the server-to-server hook the hub's onStoreDocument calls. The
 *                      call is authenticated AND bound to the node (a JWT call may only
 *                      store to its own room); the Yjs snapshot is persisted through
 *                      the PersistYjsSnapshotAction.
 *
 * Thin: token authorization lives in the read-only service, persistence in the Action;
 * no model queries here (nodes resolved via the read repository / route binding).
 */
final class NodeCollaborationController extends Controller
{
    public function __construct(
        private readonly NodeReadRepository $nodes,
        private readonly CollaborationSessionService $sessions,
        private readonly AccessResolver $access,
        private readonly HostUserResolver $users,
    ) {}

    /**
     * Issue a realtime collaboration session for the route-bound document node. The
     * service 403s an actor without at least View/Comment and encodes read-only from
     * the actor's ability (Edit → writable).
     */
    public function token(Request $request, Node $node): JsonResponse
    {
        $actor = $this->authorizable($request);

        if ($actor === null) {
            throw PermissionDeniedException::forAbility(Ability::View->value);
        }

        $session = $this->sessions->issue($actor, $node);

        return new JsonResponse(['data' => $session->toArray()]);
    }

    /**
     * The Node hub's onAuthenticate hook: resolve the user + node from the (already
     * authenticated) call and answer whether the connection is allowed and at what
     * level. allowed=false (rather than a 403) when the user holds no view access, so
     * the hub can refuse the socket cleanly.
     */
    public function authenticate(Request $request): JsonResponse
    {
        $call = ResolveCollaborationCall::call($request);
        $node = $this->resolveNode($request, $call);

        $userId = $this->callUserId($request, $call);
        $actor = $userId === null ? null : $this->users->find($userId);

        if ($actor === null) {
            return new JsonResponse(['allowed' => false, 'readOnly' => true, 'user' => null]);
        }

        $ability = $this->grantedAbility($actor, $node);

        if ($ability === null) {
            return new JsonResponse(['allowed' => false, 'readOnly' => true, 'user' => null]);
        }

        return new JsonResponse([
            'allowed' => true,
            'readOnly' => $ability !== Ability::Edit,
            'user' => ['id' => $userId],
        ]);
    }

    /**
     * The Node hub's onStoreDocument hook: persist the debounced Yjs snapshot. The
     * call must be authenticated, bound to the node (a JWT for node A may not store to
     * node B), and not read-only. The Yjs binary is the source of truth; the TipTap
     * JSON, when present, updates the projection.
     */
    public function store(Request $request, PersistYjsSnapshotAction $action): JsonResponse
    {
        $call = ResolveCollaborationCall::call($request);

        if (!$call->mayStore()) {
            throw PermissionDeniedException::forAbility(Ability::Edit->value);
        }

        $node = $this->resolveNode($request, $call);
        $yjsState = $this->yjsState($request);

        $version = $action->execute(new PersistYjsSnapshotData(
            node: $node,
            yjsState: $yjsState,
            content: $this->projection($request),
            actorId: $this->callUserId($request, $call),
        ));

        return new JsonResponse([
            'data' => [
                'node_id' => $node->getKey(),
                'version_no' => $version->version_no,
                'yjs_state_size' => $version->yjs_state_size,
            ],
        ]);
    }

    /**
     * Resolve the document node a webhook call targets from the body (room or
     * node_id), then ASSERT the call is allowed to address its room (a JWT call may
     * only act on its own room — node binding). A non-document or unbound node 403/404s.
     */
    private function resolveNode(Request $request, CollaborationCall $call): Node
    {
        $nodeId = $this->nodeId($request);
        $node = $this->nodes->find($nodeId);

        if (!$node instanceof Node) {
            abort(404);
        }

        if (!$node->kind->isDocument()) {
            throw NotADocumentNodeException::forNode($node->getKey());
        }

        $room = Config::collaborationRoom($node->getKey());

        if (!$call->mayAddressRoom($room)) {
            // The presented JWT was minted for a DIFFERENT node's room — refuse,
            // never let a token cross node boundaries.
            throw PermissionDeniedException::forAbility(Ability::View->value);
        }

        return $node;
    }

    /**
     * The node id a webhook body addresses: the `node_id` field, or the `room` field,
     * each normalised back through the channel prefix (the hub may send either, and it
     * sends node_id EQUAL to the room name — see hocuspocus-server/src/webhook.js).
     */
    private function nodeId(Request $request): int|string
    {
        $nodeId = $request->input('node_id');

        if (is_int($nodeId)) {
            return $nodeId;
        }

        // Хаб шлёт node_id РАВНЫМ имени комнаты (vaulter.documents.<id>), а не голым
        // ключом — нормализуем через префикс канала; для голого id nodeIdFromRoom()
        // возвращает значение как есть (без префикса — no-op).
        if (is_string($nodeId) && $nodeId !== '') {
            return Config::nodeIdFromRoom($nodeId);
        }

        $room = $request->input('room');

        if (is_string($room) && $room !== '') {
            return Config::nodeIdFromRoom($room);
        }

        abort(422, 'A collaboration call must carry node_id or room.');
    }

    /**
     * The raw decoded binary Yjs state from a base64 `yjs_state` body field. The Yjs
     * binary is the source of truth; an absent/empty/invalid state is a 422 (we never
     * reconstruct it from the TipTap JSON).
     */
    private function yjsState(Request $request): string
    {
        $encoded = $request->input('yjs_state');

        if (!is_string($encoded) || $encoded === '') {
            abort(422, 'A collaboration store call must carry a base64 yjs_state.');
        }

        $decoded = base64_decode($encoded, strict: true);

        if ($decoded === false || $decoded === '') {
            abort(422, 'The yjs_state must be valid base64-encoded binary.');
        }

        return $decoded;
    }

    /**
     * The optional TipTap projection from a `content`/`tiptap` body field. Null leaves
     * the prior projection intact (the Yjs state still advances).
     */
    private function projection(Request $request): ?TipTapContent
    {
        $content = $request->input('content', $request->input('tiptap'));

        if (!is_array($content) || ($content['type'] ?? null) !== 'doc') {
            return null;
        }

        /** @var array<string, mixed> $content */
        return TipTapContent::fromArray($content);
    }

    /**
     * The host user id a webhook call is attributed to: the JWT `sub` when the call
     * authenticated by JWT, else a `user_id` body field (a shared-secret call carries
     * the user out-of-band).
     */
    private function callUserId(Request $request, CollaborationCall $call): int|string|null
    {
        if ($call->subject !== null && $call->subject !== '') {
            return $call->subject;
        }

        $userId = $request->input('user_id');

        return is_int($userId) || (is_string($userId) && $userId !== '') ? $userId : null;
    }

    /**
     * The HIGHEST collaboration ability the actor holds on the node (Edit > Comment >
     * View), or null when they hold no view access — the webhook answers allowed=false.
     */
    private function grantedAbility(Authorizable $actor, Node $node): ?Ability
    {
        foreach ([Ability::Edit, Ability::Comment, Ability::View] as $ability) {
            if ($this->access->can($actor, $ability, $node)) {
                return $ability;
            }
        }

        return null;
    }

    private function authorizable(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
