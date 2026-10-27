<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Documents;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Http\Requests\CreateCollaborationSessionRequest;
use Vaulter\Documents\Http\Resources\CollaborationSessionResource;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/**
 * Join a document's realtime room: a session for its current generation, read-write for
 * an editor and read-only for everyone who may view it (View). A client whose editor
 * speaks another schema version than the document's type gets a read-only session.
 */
#[Internal]
final readonly class CreateCollaborationSessionController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(CreateCollaborationSessionRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $session = $drive->extension(DocumentOperations::class)->collaborationSession($node, $request->clientSchemaVersion());

        return (new CollaborationSessionResource($session))->response()->setStatusCode(201);
    }
}
