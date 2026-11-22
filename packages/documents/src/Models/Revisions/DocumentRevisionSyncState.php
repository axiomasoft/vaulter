<?php

declare(strict_types=1);

namespace Vaulter\Documents\Models\Revisions;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Documents\Casts\BinaryString;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * @property int|string  $revision_id
 * @property string|null $collab_protocol
 * @property string|null $collab_state_format
 * @property string|null $collab_state
 * @property int|null    $collab_state_size
 */
#[Internal]
final class DocumentRevisionSyncState extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    protected $primaryKey = 'revision_id';

    public $incrementing = false;

    public $timestamps = false;

    #[Override]
    public function getTable(): string
    {
        return app(DatabaseOptions::class)->table('document_revision_sync_states');
    }

    #[Override]
    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('[Vaulter] A document revision is immutable.');
        });
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'collab_state' => BinaryString::class,
            'collab_state_size' => 'integer',
        ];
    }
}
