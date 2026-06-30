<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Vaulter\Actions\DeleteDocumentAction;
use Vaulter\Actions\ForceDeleteDocumentAction;
use Vaulter\Actions\MoveDocumentAction;
use Vaulter\Actions\RestoreDocumentAction;
use Vaulter\Enums\Drive\BulkAction;
use Vaulter\Http\Requests\BulkDocumentRequest;
use Vaulter\Models\Documents\Document;
use Vaulter\Repositories\Access\DocumentVisibilityService;
use Vaulter\Repositories\Documents\DocumentReadRepository;

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
