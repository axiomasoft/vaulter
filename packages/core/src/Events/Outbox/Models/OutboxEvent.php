<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * One row of the neutral transactional outbox: a domain event written in the transaction of the
 * operation that caused it, whatever modules are active. event_id is UNIQUE — the outbox writer stores one
 * row per occurrence. outbox_seq is the internal monotonic order of the outbox (independent of the id
 * strategy); payload is the envelope every consumer receives. The delivery state of each consumer lives in
 * v_outbox_deliveries, not here.
 *
 * Swappable — point configuration key vaulter.models.outbox at a subclass for extra behaviour.
 *
 * @property int                  $outbox_seq
 * @property string               $event_id
 * @property int|string|null      $tenant_id
 * @property string               $scope_kind
 * @property string|null          $scope_id
 * @property string|null          $aggregate_type
 * @property string|null          $aggregate_id
 * @property int|null             $sequence
 * @property string               $type
 * @property bool                 $external
 * @property array<string, mixed> $payload
 * @property Carbon               $created_at
 * @property Carbon|null          $staged_at      set after sequence allocation, before the operation commits
 *
 * @method static Builder<static> query()
 */
#[Internal]
class OutboxEvent extends Model
{
    use GuardsWrites;
    use ResolvesVaulterConnection;

    public const null UPDATED_AT = null;

    /** @var string */
    protected $primaryKey = 'outbox_seq';

    /**
     * Fully guarded: every column is system-computed; the sole writer, OutboxWriter, fills it with forceFill().
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('outbox');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'outbox_seq' => 'integer',
            'sequence' => 'integer',
            'external' => 'boolean',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Staging is an absolute UTC instant, including when the application's local hour repeats at DST.
     * Keep its precision independent of the model's occurrence timestamp format.
     */
    protected function getStagedAtAttribute(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC');
    }

    protected function setStagedAtAttribute(DateTimeInterface|string|null $value): void
    {
        $this->attributes['staged_at'] = $value === null ? null : Carbon::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s.u');
    }
}
