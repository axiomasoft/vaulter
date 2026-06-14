<?php

declare(strict_types=1);

namespace DocVault\Models;

use DocVault\Database\Factories\DocumentPermissionFactory;
use DocVault\Enums\PermissionLevel;
use DocVault\Models\Concerns\HasDocVaultKeys;
use DocVault\Support\Config;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A grant of a permission level to a polymorphic subject (user / role / team)
 * on a document.
 *
 * @property int|string      $id
 * @property int|string      $document_id
 * @property string          $subject_type
 * @property int|string      $subject_id
 * @property PermissionLevel $permission
 * @property int|string|null $granted_by
 */
class DocumentPermission extends Model
{
    use HasDocVaultKeys;

    /** @use HasFactory<DocumentPermissionFactory> */
    use HasFactory;

    protected $table = 'dv_document_permissions';

    protected $guarded = [];

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Config::documentModel());
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory(): DocumentPermissionFactory
    {
        return DocumentPermissionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'permission' => PermissionLevel::class,
        ];
    }
}
