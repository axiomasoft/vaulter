<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Collaboration;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Vaulter\Documents\Collaboration\CollaborationHub;
use Vaulter\Documents\Http\Middleware\ResolveCollaborationCall;

/**
 * The hub's onStoreDocument (and its seed): persist the room's state behind the epoch
 * fence. Body: `{room, state (base64 Yjs), content (JSON projection), seed,
 * state_version (a seed's precondition: the version load returned)}`. A read-only token
 * or room is 403; a room of a past generation, or a seed over an existing state, is 409
 * and the hub closes the room without retrying.
 */
final readonly class HubStoreController
{
    public function __construct(private CollaborationHub $hub) {}

    public function __invoke(Request $request): JsonResponse
    {
        $call = ResolveCollaborationCall::call($request);
        $room = $this->hub->open($call, $request->input('room'));
        $content = $request->input('content');
        // The projection is a JSON object (a document): only its string keys are content.
        $content = is_array($content) ? array_filter($content, is_string(...), ARRAY_FILTER_USE_KEY) : null;

        $result = $this->hub->store(
            room: $room,
            call: $call,
            yjsState: $this->yjsState($request),
            content: $content,
            seed: $request->boolean('seed'),
            stateVersion: $this->stateVersion($request),
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

    /** The `state_version` the hub read in load (sent with a seed); present but not a non-negative integer → 422. */
    private function stateVersion(Request $request): ?int
    {
        $version = $request->input('state_version');
        if ($version === null) {
            return null;
        }
        if (!is_int($version) || $version < 0) {
            throw ValidationException::withMessages(['state_version' => ['The state_version must be a non-negative integer.']]);
        }

        return $version;
    }

    /**
     * The raw binary Yjs state from the base64 `state` field. The Yjs binary is the
     * source of truth; an absent, empty or invalid state is a 422 (it is never rebuilt
     * from the JSON here).
     */
    private function yjsState(Request $request): string
    {
        $encoded = $request->input('state');
        if (!is_string($encoded) || $encoded === '') {
            throw ValidationException::withMessages(['state' => ['A collaboration store call must carry a base64 state.']]);
        }

        $decoded = base64_decode($encoded, strict: true);
        if ($decoded === false || $decoded === '') {
            throw ValidationException::withMessages(['state' => ['The state must be valid base64-encoded binary.']]);
        }

        return $decoded;
    }
}
