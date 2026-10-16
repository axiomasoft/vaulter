<?php

declare(strict_types=1);

namespace Vaulter\Models\Content;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * @property string               $predicate_key
 * @property string               $mode
 *                                                    Server-built scan state; never accepts request mass assignment.
 * @property int|string           $id
 * @property int|string|null      $tenant_id
 * @property int|string           $drive_id
 * @property int|null             $boundary_sequence
 * @property string               $spec_key
 * @property string               $scanner_key
 * @property string               $scanner_version
 * @property string               $policy_version
 * @property string|null          $rollout_key
 * @property string               $config_fingerprint
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $activated_at
 */
#[Internal]
class ScanPolicyState extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    public $timestamps = false;

    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('scan_policy_states');
    }

    /** @return array<string,string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'updated_at' => ScanUtcDateTime::class,
            'activated_at' => ScanUtcDateTime::class,
            'boundary_sequence' => PHP_INT_SIZE === 8 ? 'integer' : 'string',
        ];
    }
}
