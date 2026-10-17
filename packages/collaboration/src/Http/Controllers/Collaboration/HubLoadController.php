<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Http\Controllers\Collaboration;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\CollaborationHub;
use Vaulter\Collaboration\Http\Middleware\ResolveCollaborationCall;

/** The hub's onLoadDocument: the Yjs working state, or the JSON to seed it from once. */
#[Internal]
final readonly class HubLoadController
{
    public function __construct(private CollaborationHub $hub) {}

    public function __invoke(Request $request): JsonResponse
    {
        $call = ResolveCollaborationCall::call($request);

        return new JsonResponse($this->hub->load($this->hub->open($call, $request->input('room'))));
    }
}
