<?php

declare(strict_types=1);

namespace Vaulter\Drive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Models\Concerns\StampsDriveTenant;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * One actor's personal state of a node: starred_at today.
 * Personal state never changes the node's sequence or timestamps.
 *
 * @property int|string      $id
 * @property int|string|null $tenant_id
 * @property int|string      $node_id
 * @property string          $actor_type
 * @property int|string      $actor_id
 * @property Carbon|null     $starred_at
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 * @property-read Node $node
 */
#[Internal]
class NodeUserState extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;
    use StampsDriveTenant;

    /** @var array<string> */
    protected $guarded = ['*'];

    #[Override]
    public function getTable(): string
    {
        return app(DatabaseOptions::class)->table('node_user_states');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(app(DriveOptions::class)->model('node'), 'node_id');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return ['starred_at' => 'datetime'];
    }
}
