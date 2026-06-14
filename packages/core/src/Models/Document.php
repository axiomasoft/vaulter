<?php

declare(strict_types=1);

namespace DocVault\Models;

use DocVault\Database\Factories\DocumentFactory;
use DocVault\Enums\DocumentType;
use DocVault\Models\Concerns\HasDocVaultKeys;
use DocVault\Models\Concerns\InteractsWithWorkspace;
use DocVault\Support\Config;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A unit of content (rich text, table, markdown, template) stored as TipTap JSON.
 *
 * @property int|string                $id
 * @property string                    $workspace_type
 * @property int|string                $workspace_id
 * @property int|string|null           $folder_id
 * @property DocumentType              $type
 * @property string                    $title
 * @property array<string, mixed>|null $content
 * @property array<string, mixed>|null $meta
 * @property Carbon|null               $created_at
 * @property Carbon|null               $updated_at
 */
class Document extends Model
{
    use HasDocVaultKeys;

    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use InteractsWithWorkspace;
    use SoftDeletes;

    protected $table = 'dv_documents';

    protected $guarded = [];

    /**
     * @return BelongsTo<DocumentFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Config::folderModel());
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Config::versionModel());
    }

    /**
     * @return HasMany<DocumentPermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(Config::permissionModel());
    }

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'content' => 'array',
            'meta' => 'array',
        ];
    }
}
