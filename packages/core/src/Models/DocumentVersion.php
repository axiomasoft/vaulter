<?php

declare(strict_types=1);

namespace DocVault\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An append-only snapshot of a document's content at save time.
 *
 * @property int         $id
 * @property int         $document_id
 * @property string|null $snapshot_label
 */
final class DocumentVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'dv_document_versions';

    protected $guarded = [];

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
