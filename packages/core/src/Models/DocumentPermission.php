<?php

declare(strict_types=1);

namespace DocVault\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A grant of a permission level to a polymorphic subject (user / role / team)
 * on a document.
 *
 * @property int    $id
 * @property int    $document_id
 * @property string $subject_type
 * @property int    $subject_id
 * @property string $permission
 */
final class DocumentPermission extends Model
{
    protected $table = 'dv_document_permissions';

    protected $guarded = [];
}
