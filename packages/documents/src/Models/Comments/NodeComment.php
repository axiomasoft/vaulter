<?php

declare(strict_types=1);

namespace Vaulter\Documents\Models\Comments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Models\Node;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * A comment on a NODE (docs/adr/0014-legacy-docvault-strangler.md шаг 1) — the additive parity twin of the legacy
 * document_id-attached {@see DocumentComment}, which stays untouched. Optionally
 * anchored to a text range and threaded under a parent comment.
 *
 * The author is a morph to a REGISTERED alias (user|…) — the Data layer writes
 * $user->getMorphClass() (honours the host's enforceMorphMap), never a raw FQCN.
 *
 * Swappable — point the configuration key ('vaulter-documents.models.node_comment') at a subclass for tenant
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
 * @property int                       $version
 * @property Carbon|null               $created_at
 * @property Carbon|null               $updated_at
 * @property-read Node $node
 */
#[Internal]
class NodeComment extends Model
{
    /** The aggregate of the operation catalogue the refusal of a stale comment write names (D47). */
    public const string AGGREGATE = 'documents.comment';

    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    /**
     * Closed allow-list: the comment's own user-authored content.
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
        return app(DatabaseConfig::class)->table('node_comments');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(app(DriveConfig::class)->model('node'), 'node_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(app(DocumentsConfig::class)->model('node_comment'), 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(app(DocumentsConfig::class)->model('node_comment'), 'parent_id');
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
            'version' => 'integer',
        ];
    }
}
