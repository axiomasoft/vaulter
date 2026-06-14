<?php

declare(strict_types=1);

namespace DocVault\Http\Controllers;

use DocVault\Abilities\DocumentAbilities;
use DocVault\Actions\CreateShareLinkAction;
use DocVault\Actions\RevokeShareLinkAction;
use DocVault\Exceptions\PermissionDeniedException;
use DocVault\Http\Requests\StoreShareRequest;
use DocVault\Http\Resources\DocumentShareResource;
use DocVault\Models\Document;
use DocVault\Repositories\DocumentReadRepository;
use DocVault\Repositories\DocumentVisibilityService;
use DocVault\Repositories\ShareReadRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShareController extends Controller
{
    public function __construct(
        private readonly DocumentReadRepository $documents,
        private readonly ShareReadRepository $shares,
        private readonly DocumentVisibilityService $visibility,
    ) {}

    public function index(Request $request, int|string $documentId): AnonymousResourceCollection
    {
        $document = $this->documents->findOrFail($documentId);
        $this->authorizeDocument($request, 'manage', $document);

        return DocumentShareResource::collection($this->shares->forDocument($document)->get());
    }

    public function store(StoreShareRequest $request, int|string $documentId, CreateShareLinkAction $action): JsonResponse
    {
        $document = $this->documents->findOrFail($documentId);
        $this->authorizeDocument($request, 'manage', $document);

        return DocumentShareResource::make($action->execute($document, $request->toData()))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(
        Request $request,
        int|string $documentId,
        int|string $shareId,
        RevokeShareLinkAction $action,
    ): Response {
        $document = $this->documents->findOrFail($documentId);
        $this->authorizeDocument($request, 'manage', $document);

        $share = $this->shares->forDocument($document)->find($shareId);

        if ($share === null) {
            throw new NotFoundHttpException('Share link not found.');
        }

        $action->execute($document, $share, $this->actorId($request));

        return response()->noContent();
    }

    /**
     * Public, token-based resolution of a shared document.
     */
    public function resolve(string $token): JsonResponse
    {
        $share = $this->shares->findActiveByToken($token);

        if ($share === null) {
            throw new NotFoundHttpException('Share link not found or expired.');
        }

        $document = $this->documents->find($share->document_id);

        if ($document === null) {
            throw new NotFoundHttpException('Share link not found or expired.');
        }

        return new JsonResponse([
            'data' => [
                'id' => $document->getKey(),
                'type' => $document->type->value,
                'title' => $document->title,
                'content' => $document->content,
                'abilities' => DocumentAbilities::fromLevel($share->permission)->toArray(),
                'share' => [
                    'permission' => $share->permission->value,
                    'expires_at' => $share->expires_at,
                ],
            ],
        ]);
    }

    private function authorizeDocument(Request $request, string $action, Document $document): void
    {
        if (!$this->visibility->can($request->user(), $action, $document)) {
            throw PermissionDeniedException::forAction($action);
        }
    }
}
