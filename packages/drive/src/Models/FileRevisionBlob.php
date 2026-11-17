<?php

declare(strict_types=1);

namespace Vaulter\Drive\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * The version ↔ blob edge: which blobs a revision is made of. The edge's media
 * pin (written with it by RevisionBlobLinks) keeps the blob alive; the table
 * is never soft-deleted and its composite primary key is (file_revision_id,
 * blob_id, role, ordinal). Swappable via configuration key
 * vaulter-drive.models.file_revision_blob.
 *
 * @property int|string $file_revision_id
 * @property int|string $blob_id
 * @property string     $role
 * @property int        $ordinal
 * @property int|null   $offset_bytes
 * @property int|null   $length_bytes
 */
#[Internal]
class FileRevisionBlob extends Pivot
{
    use GuardsWrites;
    use ResolvesVaulterConnection;

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Fully guarded: the revision edge table. Every write goes through
     * RevisionBlobLinks and FileRevisionBlobStoreRepository via the query builder
     * (updateOrInsert), never Eloquent mass-assignment, so this is pure
     * defense-in-depth against a future Eloquent-based write.
     *
     * @var array<string>|bool
     */
    protected $guarded = ['*'];

    #[Override]
    public function getTable(): string
    {
        return app(DatabaseOptions::class)->table('file_revision_blobs');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'offset_bytes' => 'integer',
            'length_bytes' => 'integer',
        ];
    }
}
