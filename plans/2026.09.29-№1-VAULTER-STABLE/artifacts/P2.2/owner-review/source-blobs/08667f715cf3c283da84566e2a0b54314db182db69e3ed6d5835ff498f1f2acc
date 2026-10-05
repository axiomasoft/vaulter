<?php

declare(strict_types=1);

namespace Vaulter\Documents\Models\Revisions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Vaulter\Documents\Casts\BinaryString;
use Vaulter\Documents\ValueObjects\OpaqueDocumentContent;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Models\Concerns\HasExtraData;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

/**
 * An append-only snapshot of a DOCUMENT NODE's content (master plan §2.5 / M4a) —
 * the NEW node-attached document-version path. It is DISTINCT from the legacy
 * {@see DocumentVersion} (document_id-attached, the v_documents path that stays
 * intact); this one hangs off a kind=document {@see Node} via node_id and lives in
 * v_node_document_versions.
 *
 * Append-only (no updated_at / no soft-delete): a committed version is immutable;
 * the autosave HEAD is the one exception the Action overwrites in place within the
 * debounce window. version_no is monotonic per node (the unique index is the
 * backbone). The TipTap JSON projection lives in `content`; the binary Yjs CRDT
 * state (the M4b collaboration source of truth) in `yjs_state`.
 *
 * @property int|string                $id
 * @property int|string|null           $tenant_id
 * @property int|string                $node_id
 * @property int                       $version_no
 * @property array<string, mixed>|null $content
 * @property string|null               $yjs_state
 * @property int|null                  $yjs_state_size
 * @property string                    $format
 * @property string|null               $content_hash
 * @property string|null               $snapshot_label
 * @property bool                      $is_autosave
 * @property string                    $status
 * @property bool                      $keep_forever
 * @property int|string|null           $created_by
 * @property Carbon|null               $created_at
 * @property-read Node $node
 */
class DocumentRevision extends Model
{
    use HasExtraData;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    public const UPDATED_AT = null;

    /**
     * Closed allow-list (F-P1.8-02): the version's own document content/label.
     * id/tenant_id/node_id/version_no/yjs_state/yjs_state_size/content_hash/
     * status/created_by are structural/server-computed — every write path that
     * sets them already uses forceFill() (DocumentRevisionStoreRepository).
     *
     * @var list<string>
     */
    protected $fillable = [
        'content',
        'format',
        'snapshot_label',
        'is_autosave',
        'keep_forever',
    ];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('node_document_versions');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Config::nodeModel(), 'node_id');
    }

    /**
     * The TipTap content of this version (the structural JSON projection).
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
     * This version's content as an opaque, unvalidated snapshot (§7 level 2) — the
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
            'version_no' => 'integer',
            'yjs_state_size' => 'integer',
            'is_autosave' => 'boolean',
            'keep_forever' => 'boolean',
        ];
    }
}
