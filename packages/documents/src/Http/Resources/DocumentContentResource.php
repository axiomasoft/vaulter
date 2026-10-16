<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Http\Support\RepresentationTag;

/**
 * A document's working state: its type, the generation (epoch and state version) the
 * content belongs to, the head revision and the content itself. Nothing in it depends
 * on who reads it, which is why its tag is the generation alone and two callers with
 * different roles get the same body and the same ETag.
 */
#[Internal]
final class DocumentContentResource extends JsonResource
{
    public function __construct(DocumentSnapshot $document)
    {
        parent::__construct($document);
    }

    /** The ETag header value: `"{nodeId}.{epoch}.{stateVersion}"`. */
    public function etag(): string
    {
        /** @var DocumentSnapshot $document */
        $document = $this->resource;

        return RepresentationTag::document($document->nodeId, $document->epoch, $document->stateVersion);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var DocumentSnapshot $document */
        $document = $this->resource;

        return [
            'type' => $document->type,
            'schema_version' => $document->schemaVersion,
            'state_version' => $document->stateVersion,
            'collab_epoch' => $document->epoch,
            'revision_id' => $document->headRevisionId,
            'content' => self::readable($document->content),
        ];
    }

    /**
     * The content as the client may safely mount it. The store-time validator never saw
     * rows written before it existed and the HTML sanitizer guards only the rendered
     * export, so a stored `link.href="javascript:…"` would otherwise travel straight into
     * the host's editor: rich text has its URL attributes scheme-scrubbed here. Another
     * type's opaque snapshot (a workbook) is returned as stored.
     *
     * @return array<string, mixed>
     */
    public static function readable(DocumentContent $content): array
    {
        return $content instanceof TipTapContent ? $content->withSanitizedUrls()->toArray() : $content->toArray();
    }
}
