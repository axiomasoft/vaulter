<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Uploads;

use Illuminate\Http\JsonResponse;
use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Http\Requests\Uploads\CreateUploadSessionRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;

/**
 * POST drives/{drive}/uploads: open a resumable session in the protocol the client asks
 * for (tus by default, or s3-multipart).
 *
 * A session for a new revision of an existing file (node_id) needs If-Match with the
 * file's current tag, so a client does not start sending bytes onto a file that changed
 * under it. The tag is checked when the session is created; the commit at the end does
 * not compare it again. A keyed retry whose tag went stale (or whose file is gone) reaches
 * the operation with a sequence no live file holds: it is answered from the receipt of the
 * first request, or refused as stale and that refusal stored — a new key never opens a
 * session on a stale tag.
 */
#[Internal]
final readonly class CreateUploadController
{
    public function __construct(
        private NodeResponses $nodes,
        private TusProtocolController $tus,
        private S3MultipartProtocolController $s3,
    ) {}

    public function __invoke(CreateUploadSessionRequest $request, string $drive): JsonResponse
    {
        $nodeId = $request->targetNodeId();
        $create = function (DriveHandle $handle) use ($request, $nodeId): JsonResponse {
            $stale = $nodeId === null ? null : $this->nodes->staleSequenceForReplay($request, $handle, $nodeId, Ability::Edit);

            return $request->protocol() === UploadProtocol::S3Multipart
                ? $this->s3->create($request, $handle, $stale)
                : $this->tus->create($request, $handle, $stale);
        };

        return $this->nodes->withinDrive($request, $drive, $create);
    }
}
