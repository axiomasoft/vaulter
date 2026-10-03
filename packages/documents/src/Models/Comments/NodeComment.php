<?php

declare(strict_types=1);

namespace Vaulter\Documents\Models\Comments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Vaulter\Documents\DocumentsModels;
use Vaulter\Models\Concerns\HasExtraData;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

/**
 * A comment on a NODE (ADR-0014 шаг 1) — the additive parity twin of the legacy
 * document_id-attached {@see DocumentComment}, which stays untouched. Optionally
 * anchored to a text range and threaded under a parent comment.
 *
 * The author is a morph to a REGISTERED alias (user|…) — the Data layer writes
 * $user->getMorphClass() (honours the host's enforceMorphMap), never a raw FQCN.
 *
 * Swappable — point config('vaulter.models.node_comment') at a subclass for tenant
 * scope.
 *
 * @property int|string                $id
 * @property int|string|null           $tenant_id
 * @property int|string                $node_id
 * @property int|string|null           $parent_id
 * @property string                    $author_type
 * @property int|string                $author_id
 * @property string                    $body
 * @property array<string, mixed>|null $anchor
 * @property Carbon|null               $resolved_at
 * @property int|string|null           $resolved_by
 * @property Carbon|null               $created_at
 * @property Carbon|null               $updated_at
 * @property-read Node $node
 */
class NodeComment extends Model
{
    use HasExtraData;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    /**
     * Closed allow-list (F-P1.8-02): the comment's own user-authored content.
     * author_type/author_id (morph), node_id/tenant_id, and resolved_at/
     * resolved_by are structural/server-only — the sole writer,
     * NodeCommentStoreRepository, uses forceFill() exclusively and is
     * unaffected by this list.
     *
     * @var list<string>
     */
    protected $fillable = [
        'body',
        'anchor',
    ];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('node_comments');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Config::nodeModel(), 'node_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DocumentsModels::comment(), 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(DocumentsModels::comment(), 'parent_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'anchor' => 'array',
            'resolved_at' => 'datetime',
        ];
    }
}
