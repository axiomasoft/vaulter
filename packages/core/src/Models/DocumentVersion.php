<?php

declare(strict_types=1);

namespace Vaulter\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Vaulter\Database\Factories\DocumentVersionFactory;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Support\Config;

/**
 * An append-only snapshot of a document's content at save time.
 *
 * @property int|string           $id
 * @property int|string           $document_id
 * @property array<string, mixed> $content
 * @property string|null          $snapshot_label
 * @property int|string|null      $created_by
 * @property Carbon|null          $created_at
 */
class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory;

    use HasVaulterKeys;

    public const UPDATED_AT = null;

    protected $guarded = [];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('document_versions');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Config::documentModel());
    }

    protected static function newFactory(): DocumentVersionFactory
    {
        return DocumentVersionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }
}
