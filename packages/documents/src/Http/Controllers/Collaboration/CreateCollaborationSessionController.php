<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Collaboration;

use Illuminate\Http\JsonResponse;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Requests\CreateCollaborationSessionRequest;
use Vaulter\Documents\Http\Resources\CollaborationSessionResource;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/**
 * Join a document's realtime room: a session for its current generation, read-write for
 * an editor and read-only for everyone who may view it (View). A client whose editor
 * speaks another schema version than the document's type gets a read-only session.
 */
final readonly class CreateCollaborationSessionController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private DocumentsManager $documents) {}

    public function __invoke(CreateCollaborationSessionRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $session = $this->documents->in($drive)->collaborationSession($node, $request->clientSchemaVersion());

        return (new CollaborationSessionResource($session))->response()->setStatusCode(201);
    }
}
