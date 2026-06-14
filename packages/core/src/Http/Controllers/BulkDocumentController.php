<?php

declare(strict_types=1);

namespace DocVault\Http\Controllers;

use DocVault\Actions\DeleteDocumentAction;
use DocVault\Actions\ForceDeleteDocumentAction;
use DocVault\Actions\MoveDocumentAction;
use DocVault\Actions\RestoreDocumentAction;
use DocVault\Enums\BulkAction;
use DocVault\Http\Requests\BulkDocumentRequest;
use DocVault\Models\Document;
use DocVault\Repositories\DocumentReadRepository;
use DocVault\Repositories\DocumentVisibilityService;
use Illuminate\Http\JsonResponse;

final class BulkDocumentController extends Controller
{
    public function __construct(
        private readonly DocumentReadRepository $documents,
        private readonly DocumentVisibilityService $visibility,
    ) {}

    public function __invoke(
        BulkDocumentRequest $request,
        DeleteDocumentAction $delete,
        RestoreDocumentAction $restore,
        MoveDocumentAction $move,
        ForceDeleteDocumentAction $forceDelete,
    ): JsonResponse {
        $action = $request->action();
        $folderId = $request->folderId();
        $actorId = $this->actorId($request);

        /** @var list<int|string> $processed */
        $processed = [];
        /** @var list<int|string> $skipped */
        $skipped = [];

        foreach ($request->ids() as $id) {
            $document = $action->operatesOnTrash()
                ? $this->documents->findWithTrashed($id)
                : $this->documents->find($id);

            if ($document === null || !$this->visibility->can($request->user(), $action->ability(), $document)) {
                $skipped[] = $id;

                continue;
            }

            $this->perform($action, $document, $folderId, $actorId, $delete, $restore, $move, $forceDelete);
            $processed[] = $id;
        }

        return new JsonResponse([
            'data' => [
                'action' => $action->value,
                'processed' => $processed,
                'skipped' => $skipped,
            ],
        ]);
    }

    private function perform(
        BulkAction $action,
        Document $document,
        int|string|null $folderId,
        int|string|null $actorId,
        DeleteDocumentAction $delete,
        RestoreDocumentAction $restore,
        MoveDocumentAction $move,
        ForceDeleteDocumentAction $forceDelete,
    ): void {
        match ($action) {
            BulkAction::Delete => $delete->execute($document, $actorId),
            BulkAction::Restore => $restore->execute($document, $actorId),
            BulkAction::Move => $move->execute($document, $folderId, $actorId),
            BulkAction::ForceDelete => $forceDelete->execute($document, $actorId),
        };
    }
}
