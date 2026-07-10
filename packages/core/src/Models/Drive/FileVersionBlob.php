<?php

declare(strict_types=1);

namespace Vaulter\Models\Drive;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Vaulter\Support\Config;

/**
 * The version ↔ blob edge (master plan §2.4): the GC truth. A blob is live iff an
 * edge here references it, so this table is never soft-deleted and its composite
 * primary key is (file_version_id, blob_id, role, ordinal). Swappable via
 * config('vaulter.models.file_version_blob').
 *
 * @property int|string $file_version_id
 * @property int|string $blob_id
 * @property string     $role
 * @property int        $ordinal
 * @property int|null   $offset_bytes
 * @property int|null   $length_bytes
 */
class FileVersionBlob extends Pivot
{
    public $incrementing = false;

    public $timestamps = false;

    /**
     * Fully guarded (F-P1.8-02): the GC-liveness edge table. Every write goes
     * through FileVersionBlobStoreRepository via the query builder
     * (updateOrInsert), never Eloquent mass-assignment, so this is pure
     * defense-in-depth against a future Eloquent-based write.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('file_version_blobs');
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
