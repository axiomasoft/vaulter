<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Http\Support\Preconditions;
use Vaulter\Support\Identity;

/**
 * How the documents routes answer with a document's content, and how a conditional
 * write learns what to expect.
 *
 * The If-Match header is compared with the tag of the content as it is right now, after
 * the document was found as this caller and the caller was seen to hold Edit (so an
 * invisible document is a 404 and a missing ability a 403 before any 428 or 412). The
 * matching state version is what the operation then checks again, atomically, while it
 * writes — the hub storing a new state in between makes that write fail with the same
 * 412.
 */
#[Internal]
final readonly class DocumentResponses
{
    public function __construct(private DocumentsManager $documents) {}

    public function content(DocumentSnapshot $document, int $status = 200): JsonResponse
    {
        $resource = new DocumentContentResource($document);

        return $resource->response()->setStatusCode($status)->setEtag($resource->etag());
    }

    /**
     * The state version a conditional content write expects.
     *
     * @throws StaleDocumentStateException when no listed tag is the current one (412)
     */
    public function expectedStateVersion(Request $request, DriveHandle $drive, string $nodeId): int
    {
        // View and the document kind first: a caller who cannot see the node learns nothing.
        $document = $this->documents->in($drive)->content($nodeId);
        $node = $drive->nodes()->find($nodeId);
        $abilities = $drive->nodes()->abilitiesMany([$node])[$node->id] ?? throw NodeNotFoundException::forId($nodeId);
        if (!($abilities->flags[Ability::Edit->value] ?? false)) {
            throw PermissionDeniedException::forAbility(Ability::Edit->value);
        }

        // The ability flags do not know a drive is archived; refuse it before any precondition.
        $model = $drive->model();
        if ($model->status === DriveStatus::Archived) {
            throw DriveArchivedException::forDrive(Identity::stringify($model->getKey()));
        }

        return Preconditions::matchDocument(
            $request,
            (new DocumentContentResource($document))->etag(),
            $document->stateVersion,
            new StaleDocumentStateException(expected: $document->stateVersion, actual: $document->stateVersion, nodeId: $nodeId),
        );
    }
}
