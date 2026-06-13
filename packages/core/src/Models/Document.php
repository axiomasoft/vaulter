<?php

declare(strict_types=1);

namespace DocVault\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A unit of content (rich text, table, markdown, template) stored as TipTap JSON.
 *
 * @property int      $id
 * @property string   $workspace_type
 * @property int      $workspace_id
 * @property int|null $folder_id
 * @property string   $type
 * @property string   $title
 */
final class Document extends Model
{
    use SoftDeletes;

    protected $table = 'dv_documents';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'meta' => 'array',
        ];
    }
}
