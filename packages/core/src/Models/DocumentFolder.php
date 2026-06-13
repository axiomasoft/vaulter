<?php

declare(strict_types=1);

namespace DocVault\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A node in the hierarchical folder tree (Google Drive style) scoped to a workspace.
 *
 * @property int      $id
 * @property string   $workspace_type
 * @property int      $workspace_id
 * @property int|null $parent_id
 * @property string   $name
 * @property int      $order
 */
final class DocumentFolder extends Model
{
    protected $table = 'dv_document_folders';

    protected $guarded = [];

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
