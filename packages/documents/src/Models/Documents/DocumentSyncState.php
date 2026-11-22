<?php

declare(strict_types=1);

namespace Vaulter\Documents\Models\Documents;

use Illuminate\Database\Eloquent\Model;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Documents\Casts\BinaryString;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * @property int|string  $document_id
 * @property string|null $collab_protocol
 * @property string|null $collab_state_format
 * @property string|null $collab_state
 * @property int|null    $collab_state_size
 * @property string|null $pending_protocol
 * @property string|null $pending_state_format
 * @property int|null    $pending_schema_version
 * @property int|null    $pending_generation
 * @property int|null    $pending_through_seq
 */
#[Internal]
final class DocumentSyncState extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    protected $primaryKey = 'document_id';

    public $incrementing = false;

    public $timestamps = false;

    #[Override]
    public function getTable(): string
    {
        return app(DatabaseOptions::class)->table('document_sync_states');
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'collab_state' => BinaryString::class,
            'collab_state_size' => 'integer',
            'pending_schema_version' => 'integer',
            'pending_generation' => 'integer',
            'pending_through_seq' => 'integer',
        ];
    }
}
