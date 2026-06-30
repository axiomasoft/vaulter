<?php

declare(strict_types=1);

namespace Vaulter\Models\Documents;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Vaulter\Database\Factories\DocumentShareFactory;
use Vaulter\Enums\Access\PermissionLevel;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Support\Config;

/**
 * A tokenized public share of a document, granting a fixed permission level until
 * an optional expiry.
 *
 * @property int|string      $id
 * @property int|string      $document_id
 * @property string          $token
 * @property PermissionLevel $permission
 * @property Carbon|null     $expires_at
 * @property int|string|null $created_by
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 */
class DocumentShare extends Model
{
    /** @use HasFactory<DocumentShareFactory> */
    use HasFactory;

    use HasVaulterKeys;

    protected $guarded = [];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('document_shares');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Config::documentModel());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return !$this->isExpired();
    }

    protected static function newFactory(): DocumentShareFactory
    {
        return DocumentShareFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'permission' => PermissionLevel::class,
            'expires_at' => 'datetime',
        ];
    }
}
