<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Uploads;

use Illuminate\Http\JsonResponse;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Http\Requests\Uploads\CreateUploadSessionRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Enums\Access\Ability;

/**
 * POST drives/{drive}/uploads: open a resumable session in the protocol the client asks
 * for (tus by default, or s3-multipart).
 *
 * A session for a new revision of an existing file (node_id) needs If-Match with the
 * file's current tag, so a client does not start sending bytes onto a file that changed
 * under it. The tag is checked when the session is created; the commit at the end does
 * not compare it again.
 */
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
            if ($nodeId !== null) {
                $this->nodes->expectedSequence($request, $handle, $nodeId, Ability::Edit);
            }

            return $request->protocol() === UploadProtocol::S3Multipart
                ? $this->s3->create($request, $handle)
                : $this->tus->create($request, $handle);
        };

        return $this->nodes->withinDrive($request, $drive, $create);
    }
}
