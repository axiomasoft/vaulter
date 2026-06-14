<?php

declare(strict_types=1);

namespace DocVault\Models;

use DocVault\Database\Factories\DocumentFolderFactory;
use DocVault\Models\Concerns\HasDocVaultKeys;
use DocVault\Models\Concerns\InteractsWithWorkspace;
use DocVault\Support\Config;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node in the hierarchical folder tree (Google Drive style) scoped to a workspace.
 *
 * @property int|string      $id
 * @property string          $workspace_type
 * @property int|string      $workspace_id
 * @property int|string|null $parent_id
 * @property string          $name
 * @property int             $order
 */
class DocumentFolder extends Model
{
    use HasDocVaultKeys;

    /** @use HasFactory<DocumentFolderFactory> */
    use HasFactory;

    use InteractsWithWorkspace;

    protected $guarded = [];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('document_folders');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Config::folderModel(), 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Config::folderModel(), 'parent_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Config::documentModel());
    }

    protected static function newFactory(): DocumentFolderFactory
    {
        return DocumentFolderFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }
}
