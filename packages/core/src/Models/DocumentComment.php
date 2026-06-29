<?php

declare(strict_types=1);

namespace Vaulter\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Vaulter\Database\Factories\DocumentCommentFactory;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Support\Config;

/**
 * A comment on a document, optionally anchored to a text range and threaded
 * under a parent comment.
 *
 * @property int|string                $id
 * @property int|string                $document_id
 * @property int|string|null           $parent_id
 * @property string                    $author_type
 * @property int|string                $author_id
 * @property string                    $body
 * @property array<string, mixed>|null $anchor
 * @property Carbon|null               $resolved_at
 * @property Carbon|null               $created_at
 * @property Carbon|null               $updated_at
 */
class DocumentComment extends Model
{
    /** @use HasFactory<DocumentCommentFactory> */
    use HasFactory;

    use HasVaulterKeys;

    protected $guarded = [];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('document_comments');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Config::documentModel());
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Config::commentModel(), 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Config::commentModel(), 'parent_id');
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

    protected static function newFactory(): DocumentCommentFactory
    {
        return DocumentCommentFactory::new();
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
