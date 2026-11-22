<?php

declare(strict_types=1);

namespace Vaulter\Documents\Models\Revisions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\State\CollaborationStateBinding;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * An immutable checkpoint of a document's working state (v_document_revisions).
 * Autosave checkpoints are taken on an interval, named ones on snapshot(), and a
 * restore copies an older checkpoint forward into a new one (restored_from_revision_id).
 * After INSERT only keep_forever may change — the model refuses any other update,
 * so a checkpoint id always names the same bytes (cacheable exports, stable history).
 *
 * @property int|string      $id
 * @property int|string|null $tenant_id
 * @property int|string      $document_id
 * @property int             $number
 * @property string          $type
 * @property int             $collab_epoch
 * @property int             $schema_version
 * @property int             $state_version
 * @property string|null     $label
 * @property bool            $is_autosave
 * @property bool            $keep_forever
 * @property string          $status
 * @property int|string|null $restored_from_revision_id
 * @property int|string|null $created_by
 * @property Carbon|null     $created_at
 * @property-read DocumentRevisionPayload|null $payload
 * @property-read DocumentRevisionSyncState|null $syncState
 * @property-read Document              $document
 * @property-read DocumentRevision|null $restoredFrom
 */
#[Internal]
class DocumentRevision extends Model
{
    use GuardsWrites;
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

    /** @return HasOne<DocumentRevisionPayload, $this> */
    public function payload(): HasOne
    {
        return $this->hasOne(DocumentRevisionPayload::class, 'revision_id');
    }

    /** @return HasOne<DocumentRevisionSyncState, $this> */
    public function syncState(): HasOne
    {
        return $this->hasOne(DocumentRevisionSyncState::class, 'revision_id');
    }

    public function collaborationBinding(): ?CollaborationStateBinding
    {
        $sync = $this->syncState;

        return $sync?->collab_protocol === null ? null : new CollaborationStateBinding(
            $sync->collab_protocol, $sync->collab_state_format ?? throw new LogicException('Incomplete stored binding.'), $this->schema_version, $this->collab_epoch,
        );
    }

    #[Override]
    protected static function booted(): void
    {
        static::updating(static function (self $revision): void {
            $changed = array_keys($revision->getDirty());
            if (array_diff($changed, [self::RETENTION_COLUMN]) !== []) {
                throw new LogicException('[Vaulter] A document revision is immutable; only keep_forever may change.');
            }
        });
    }

    #[Override]
    public function getTable(): string
    {
        return app(DatabaseOptions::class)->table('document_revisions');
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
     * READING NEVER THROWS. A snapshot written before today's
     * allow-list existed — or by a host extension since switched off — DECAYS: unknown
     * nodes/marks/attribute keys are stripped and a valid doc comes back. Store-time
     * validation is the strict gate ({@see TipTapContent::fromArray()}); making the read
     * path equally strict meant a single legacy document threw on preview, export and in
     * the listing UI, i.e. an XSS barrier bought at the price of denial of service.
     *
     * Attribute VALUES survive the decay by design: a legacy `href="javascript:…"` reaches
     * the RENDERER, where the render-time HTML sanitizer (second echelon) neutralises
     * it — that is the echelon's only real input.
     *
     * That covers the HTML paths (export/preview) and NOTHING else, so the JSON read path —
     * DocumentRevisionResource, which hands the content straight to the editor with no
     * renderer in between — scrubs the URL values itself through
     * {@see TipTapContent::withSanitizedUrls()}. A caller that uses
     * tipTapContent() to build its OWN client-facing payload must do the same.
     */
    public function tipTapContent(): TipTapContent
    {
        $content = $this->payload?->content;

        return is_array($content) ? TipTapContent::decayFrom($content) : TipTapContent::empty();
    }

    /**
     * This checkpoint's content as an opaque, unvalidated snapshot (level 2) — the
     * shape a non-rich-text handler's content round-trips as, e.g. a Univer workbook.
     */
    public function opaqueContent(): OpaqueContent
    {
        $content = $this->payload?->content;

        return OpaqueContent::fromArray(is_array($content) ? $content : []);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'collab_epoch' => 'integer',
            'schema_version' => 'integer',
            'state_version' => 'integer',
            'is_autosave' => 'boolean',
            'keep_forever' => 'boolean',
        ];
    }
}
