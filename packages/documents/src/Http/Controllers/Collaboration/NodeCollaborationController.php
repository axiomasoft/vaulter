<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Collaboration;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Access\AccessResolver;
use Vaulter\Documents\Collaboration\CollaborationCall;
use Vaulter\Documents\Collaboration\CollaborationHub;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Contracts\HostUserResolver;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Middleware\ResolveCollaborationCall;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;
use Vaulter\VaulterManager;

/**
 * The M4b realtime-collaboration HTTP surface for document nodes (master plan §7 /
 * ADR-0001). Three thin endpoints:
 *
 *   - token()        — the client-facing connect handshake through
 *                      DocumentOperations::collaborationSession() (View to connect,
 *                      read-only unless Edit), returning the transport-agnostic
 *                      CollaborationSession descriptor.
 *   - authenticate() — the server-to-server hook the Node hub's onAuthenticate calls.
 *                      The call is already authenticated by ResolveCollaborationCall;
 *                      this resolves the user's ability on the node and answers
 *                      {allowed, readOnly, user}; content that cannot be saved back
 *                      is read-only whatever the ability.
 *   - store()        — the server-to-server hook the hub's onStoreDocument calls. The
 *                      call is authenticated AND bound to the node (a JWT call may only
 *                      store to its own room); the Yjs snapshot is persisted through
 *                      the PersistCollaborationStateAction.
 *
 * Thin: the token goes through the documents operations; the hub calls, which carry
 * no user session, go through the internal CollaborationHub (node lookup, room
 * binding, persistence). No model queries here.
 */
final class NodeCollaborationController extends Controller
{
    public function __construct(
        private readonly VaulterManager $vaulter,
        private readonly DocumentsManager $documents,
        private readonly CollaborationHub $hub,
        private readonly AccessResolver $access,
        private readonly HostUserResolver $users,
        private readonly DocumentsConfig $documentsConfig,
    ) {}

    /**
     * Issue a realtime collaboration session for the route-bound document node: a
     * user without View gets 404, read-only is encoded from the user's ability (Edit →
     * writable).
     */
    public function token(Request $request, Node $node): JsonResponse
    {
        $actor = $this->authorizable($request) ?? throw MissingActorException::required();
        $drive = $node->storage()->first() ?? throw NodeNotFoundException::forId(Identity::stringify($node->getKey()));

        $session = $this->documents
            ->in($this->vaulter->drive(ownerOrDrive: $drive)->actingAs($actor))
            ->collaborationSession(Identity::stringify($node->getKey()));

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
        $node = $this->hub->node($this->nodeId($request), $call);

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
            // Content this installation cannot save back is shared read-only, as by
            // DocumentOperations::collaborationSession().
            'readOnly' => $ability !== Ability::Edit || !$this->hub->writable($node),
            'user' => ['id' => $userId],
        ]);
    }

    /**
     * The Node hub's onStoreDocument hook: persist the debounced Yjs snapshot. The
     * call must be authenticated, bound to the node (a JWT for node A may not store to
     * node B), and not read-only. The Yjs binary is the source of truth; the JSON, when
     * present, updates the projection.
     */
    public function store(Request $request): JsonResponse
    {
        $call = ResolveCollaborationCall::call($request);

        if (!$call->mayStore()) {
            throw PermissionDeniedException::forAbility(Ability::Edit->value);
        }

        $node = $this->hub->node($this->nodeId($request), $call);
        $yjsState = $this->yjsState($request);

        $result = $this->hub->store($node, $yjsState, $this->projection($request), $this->callUserId($request, $call));

        return new JsonResponse([
            'data' => [
                'node_id' => $node->getKey(),
                'version_no' => $result->document->currentRevision?->number,
                'yjs_state_size' => $result->document->yjs_state_size,
            ],
        ]);
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
            return $this->documentsConfig->nodeIdFromRoom($nodeId);
        }

        $room = $request->input('room');

        if (is_string($room) && $room !== '') {
            return $this->documentsConfig->nodeIdFromRoom($room);
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
     * The optional JSON projection from a `content`/`tiptap` body field, decoded later by
     * the document's type. Null leaves the prior projection intact (the Yjs state still
     * advances).
     *
     * @return array<string, mixed>|null
     */
    private function projection(Request $request): ?array
    {
        $content = $request->input('content', $request->input('tiptap'));

        /** @var array<string, mixed>|null */
        return is_array($content) ? $content : null;
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
