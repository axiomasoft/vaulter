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
 * Server-built scan state; never accepts request mass assignment.
 *
 * @property int|string           $id
 * @property int|string|null      $tenant_id
 * @property int|string           $drive_id
 * @property int                  $target_sequence
 * @property int                  $last_admission_sequence
 * @property int|string|null      $last_revision_id
 * @property string               $spec_key
 * @property string               $request_key
 * @property string               $state
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Internal]
class ScanBackfillRun extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    public $timestamps = false;

    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('scan_backfill_runs');
    }

    /** @return array<string,string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'created_at' => ScanUtcDateTime::class,
            'updated_at' => ScanUtcDateTime::class,
            'target_sequence' => PHP_INT_SIZE === 8 ? 'integer' : 'string',
            'last_admission_sequence' => PHP_INT_SIZE === 8 ? 'integer' : 'string',
        ];
    }
}
