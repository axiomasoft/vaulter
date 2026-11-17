<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;

/**
 * An immutable checkpoint of a document. The history listing leaves the content out to
 * stay light; the single-revision read carries it.
 */
#[Internal]
final class DocumentRevisionResource extends JsonResource
{
    public function __construct(DocumentRevisionSnapshot $revision, private readonly ?DocumentContent $content = null)
    {
        parent::__construct($revision);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var DocumentRevisionSnapshot $revision */
        $revision = $this->resource;

        return [
            'id' => $revision->id,
            'number' => $revision->number,
            'schema_version' => $revision->schemaVersion,
            'collab_protocol' => $revision->collabProtocol,
            'collab_state_format' => $revision->collabStateFormat,
            'type' => $revision->type,
            'collab_epoch' => $revision->epoch,
            'label' => $revision->label,
            'is_autosave' => $revision->isAutosave,
            'keep_forever' => $revision->keepForever,
            'content_hash' => $revision->contentHash,
            'is_head' => $revision->isHead,
            'created_by' => $revision->createdBy,
            'created_at' => SnapshotValue::iso($revision->createdAt),
        ] + ($this->content === null ? [] : ['content' => DocumentContentResource::readable($this->content)]);
    }
}
