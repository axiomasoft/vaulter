<?php

declare(strict_types=1);

namespace Vaulter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Vaulter\Models\Concerns\HasExtraData;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Support\Config;
use Vaulter\ValueObjects\TipTapContent;

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
class NodeDocumentVersion extends Model
{
    use HasExtraData;
    use HasVaulterKeys;

    public const UPDATED_AT = null;

    protected $guarded = [];

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
     * The validated TipTap content of this version (the structural JSON projection).
     * An empty/invalid snapshot decays to an empty doc rather than throwing on read.
     */
    public function tipTapContent(): TipTapContent
    {
        $content = $this->content;

        if (is_array($content) && ($content['type'] ?? null) === 'doc') {
            return TipTapContent::fromArray($content);
        }

        return TipTapContent::empty();
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'version_no' => 'integer',
            'yjs_state_size' => 'integer',
            'is_autosave' => 'boolean',
            'keep_forever' => 'boolean',
        ];
    }
}
