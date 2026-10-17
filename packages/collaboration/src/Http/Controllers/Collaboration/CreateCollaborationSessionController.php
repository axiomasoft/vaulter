<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Http\Controllers\Collaboration;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Http\Requests\CreateCollaborationSessionRequest;
use Vaulter\Collaboration\Http\Resources\CollaborationSessionResource;
use Vaulter\Collaboration\Operations\CollaborationOperations;
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
        $session = $drive->extension(CollaborationOperations::class)->session($node, $request->clientSchemaVersion());

        return (new CollaborationSessionResource($session))->response()->setStatusCode(201);
    }
}
