<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Http\Controllers\Collaboration;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\CollaborationHub;
use Vaulter\Collaboration\Http\Middleware\ResolveCollaborationCall;
use Vaulter\Collaboration\Http\Support\HubProtocol;

/**
 * The hub's onLoadDocument. Protocol 2 (header `X-Vaulter-Collab-Protocol: 2`): the checkpoint, the log sequence it
 * covers and the committed tail to replay on top of it. Protocol 1 (no header, until P5.7): the Yjs working state, or
 * the JSON to seed it from once.
 */
#[Internal]
final readonly class HubLoadController
{
    public function __construct(private CollaborationHub $hub) {}

    public function __invoke(Request $request): JsonResponse
    {
        $v2 = HubProtocol::negotiate($request);
        $call = ResolveCollaborationCall::call($request);
        $room = $this->hub->open($call, $request->input('room'));

        return $v2
            ? HubProtocol::mark(new JsonResponse($this->hub->loadLog($room, $call)))
            : new JsonResponse($this->hub->load($room));
    }
}
