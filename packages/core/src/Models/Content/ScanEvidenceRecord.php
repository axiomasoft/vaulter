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
 * Server-built scan state; never accepts request mass assignment.
 *
 * @property int|string           $id
 * @property int|string|null      $tenant_id
 * @property int|string           $drive_id
 * @property int|string           $revision_id
 * @property int|string|null      $blob_id
 * @property int|string           $obligation_id
 * @property int|string           $request_id
 * @property int                  $generation
 * @property int                  $attempt
 * @property int                  $size_bytes
 * @property bool                 $retryable
 * @property string               $scope_key
 * @property string               $content_identity
 * @property string               $spec_key
 * @property string               $sha256
 * @property string               $scanner_key
 * @property string               $scanner_version
 * @property string               $policy_version
 * @property string               $outcome
 * @property string|null          $reason
 * @property string|null          $revocation_reason
 * @property int|string|null      $reused_from_id
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable      $observed_at
 */
class ScanEvidenceRecord extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    public $timestamps = false;

    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('scan_evidence');
    }

    /** @return array<string,string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'observed_at' => ScanUtcDateTime::class,
            'revoked_at' => ScanUtcDateTime::class,
            'generation' => PHP_INT_SIZE === 8 ? 'integer' : 'string',
            'attempt' => PHP_INT_SIZE === 8 ? 'integer' : 'string',
            'size_bytes' => PHP_INT_SIZE === 8 ? 'integer' : 'string',
            'retryable' => 'boolean',
        ];
    }
}
