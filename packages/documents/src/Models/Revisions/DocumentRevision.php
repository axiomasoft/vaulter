<?php

declare(strict_types=1);

namespace Vaulter\Documents\Models\Revisions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Documents\Casts\BinaryString;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\ValueObjects\OpaqueDocumentContent;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * An immutable checkpoint of a document's working state (v_document_revisions).
 * Autosave checkpoints are taken on an interval, named ones on snapshot(), and a
 * restore copies an older checkpoint forward into a new one (restored_from_revision_id).
 * After INSERT only keep_forever may change — the model refuses any other update,
 * so a checkpoint id always names the same bytes (cacheable exports, stable history).
 *
 * @property int|string                $id
 * @property int|string|null           $tenant_id
 * @property int|string                $document_id
 * @property int                       $number
 * @property int                       $schema_version
 * @property array<string, mixed>|null $content
 * @property string|null               $content_hash
 * @property int|null                  $content_size
 * @property string|null               $yjs_state
 * @property int|null                  $yjs_state_size
 * @property int                       $state_version
 * @property string|null               $label
 * @property bool                      $is_autosave
 * @property bool                      $keep_forever
 * @property string                    $status
 * @property int|string|null           $restored_from_revision_id
 * @property int|string|null           $created_by
 * @property Carbon|null               $created_at
 * @property-read Document              $document
 * @property-read DocumentRevision|null $restoredFrom
 */
class DocumentRevision extends Model
{
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    public const UPDATED_AT = null;

    /** The only column a stored checkpoint may change. */
    public const string RETENTION_COLUMN = 'keep_forever';

    /**
     * Nothing is mass-assignable: the payload is server-computed by
     * DocumentStateWriter and written with forceFill().
     *
     * @var list<string>
     */
    protected $fillable = [];

    #[\Override]
    protected static function booted(): void
    {
        static::updating(static function (self $revision): void {
            $changed = array_keys($revision->getDirty());
            if (array_diff($changed, [self::RETENTION_COLUMN]) !== []) {
                throw new LogicException('[Vaulter] A document revision is immutable; only keep_forever may change.');
            }
        });
    }

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('document_revisions');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /**
     * The checkpoint this one was restored from, while that one still exists.
     *
     * @return BelongsTo<DocumentRevision, $this>
     */
    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_revision_id');
    }

    /**
     * The TipTap content of this checkpoint (the structural JSON projection).
     *
     * READING NEVER THROWS (VLT-REMED D29 / R-01). A snapshot written before today's
     * allow-list existed — or by a host extension since switched off — DECAYS: unknown
     * nodes/marks/attribute keys are stripped and a valid doc comes back. Store-time
     * validation is the strict gate ({@see TipTapContent::fromArray()}); making the read
     * path equally strict meant a single legacy document threw on preview, export and in
     * the listing UI, i.e. an XSS barrier bought at the price of denial of service.
     *
     * Attribute VALUES survive the decay by design: a legacy `href="javascript:…"` reaches
     * the RENDERER, where the render-time HTML sanitizer (second echelon, D4) neutralises
     * it — that is the echelon's only real input.
     *
     * That covers the HTML paths (export/preview) and NOTHING else, so the JSON read path —
     * DocumentRevisionResource, which hands the content straight to the editor with no
     * renderer in between — scrubs the URL values itself through
     * {@see TipTapContent::withSanitizedUrls()} (VLT-REMED P5.2 / R-12). A caller that uses
     * tipTapContent() to build its OWN client-facing payload must do the same.
     */
    public function tipTapContent(): TipTapContent
    {
        $content = $this->content;

        return is_array($content) ? TipTapContent::decayFrom($content) : TipTapContent::empty();
    }

    /**
     * This checkpoint's content as an opaque, unvalidated snapshot (§7 level 2) — the
     * shape a non-rich-text handler's content round-trips as, e.g. a Univer workbook.
     */
    public function opaqueContent(): OpaqueDocumentContent
    {
        $content = $this->content;

        return OpaqueDocumentContent::fromArray(is_array($content) ? $content : []);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'content' => 'array',
            // Postgres returns bytea as a stream resource; normalise to a string on every
            // driver so consumers (copy/restore actions, collab load) never see a resource.
            'yjs_state' => BinaryString::class,
            'number' => 'integer',
            'schema_version' => 'integer',
            'state_version' => 'integer',
            'content_size' => 'integer',
            'yjs_state_size' => 'integer',
            'is_autosave' => 'boolean',
            'keep_forever' => 'boolean',
        ];
    }
}
