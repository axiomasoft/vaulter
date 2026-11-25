<?php

declare(strict_types=1);

namespace Vaulter\Drive\Models\Content;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Drive\Enums\ScanWorkState;
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
 * @property int                  $generation
 * @property int                  $attempt
 * @property int                  $size_bytes
 * @property bool                 $retryable
 * @property string|null          $lease_token
 * @property string               $scope_key
 * @property string               $content_identity
 * @property string               $spec_key
 * @property string               $sha256
 * @property string               $scanner_key
 * @property string               $scanner_version
 * @property string               $policy_version
 * @property string               $request_key
 * @property ScanWorkState        $state
 * @property string|null          $outcome
 * @property string|null          $reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $lease_expires_at
 * @property CarbonImmutable|null $last_published_at
 * @property CarbonImmutable      $available_at
 */
#[Internal]
class ScanObligation extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    public $timestamps = false;

    protected $guarded = ['*'];

    #[Override]
    public function getTable(): string
    {
        return app(DatabaseOptions::class)->table('scan_obligations');
    }

    /** @return array<string,string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'created_at' => ScanUtcDateTime::class,
            'updated_at' => ScanUtcDateTime::class,
            'available_at' => ScanUtcDateTime::class,
            'lease_expires_at' => ScanUtcDateTime::class,
            'last_published_at' => ScanUtcDateTime::class,
            'generation' => PHP_INT_SIZE === 8 ? 'integer' : 'string',
            'attempt' => PHP_INT_SIZE === 8 ? 'integer' : 'string',
            'size_bytes' => PHP_INT_SIZE === 8 ? 'integer' : 'string',
            'retryable' => 'boolean',
            'state' => ScanWorkState::class,
        ];
    }
}
