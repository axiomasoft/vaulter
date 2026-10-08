<?php

declare(strict_types=1);

namespace Vaulter\Models\Content;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * @property string               $correlation_id
 *                                                Server-built scan state; never accepts request mass assignment.
 * @property int|string           $id
 * @property int|string           $obligation_id
 * @property int                  $generation
 * @property string               $request_key
 * @property string|null          $reason
 * @property CarbonImmutable|null $requested_at
 */
class ScanRequest extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    public $timestamps = false;

    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('scan_requests');
    }

    /** @return array<string,string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'requested_at' => ScanUtcDateTime::class,
            'generation' => PHP_INT_SIZE === 8 ? 'integer' : 'string',
        ];
    }
}
