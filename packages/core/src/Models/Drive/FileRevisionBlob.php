<?php

declare(strict_types=1);

namespace Vaulter\Models\Drive;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * The version ↔ blob edge (master plan §2.4): the GC truth. A blob is live iff an
 * edge here references it, so this table is never soft-deleted and its composite
 * primary key is (file_revision_id, blob_id, role, ordinal). Swappable via
 * configuration key vaulter.models.file_revision_blob.
 *
 * @property int|string $file_revision_id
 * @property int|string $blob_id
 * @property string     $role
 * @property int        $ordinal
 * @property int|null   $offset_bytes
 * @property int|null   $length_bytes
 */
class FileRevisionBlob extends Pivot
{
    use ResolvesVaulterConnection;

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Fully guarded (F-P1.8-02): the GC-liveness edge table. Every write goes
     * through FileRevisionBlobStoreRepository via the query builder
     * (updateOrInsert), never Eloquent mass-assignment, so this is pure
     * defense-in-depth against a future Eloquent-based write.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('file_revision_blobs');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'offset_bytes' => 'integer',
            'length_bytes' => 'integer',
        ];
    }
}
