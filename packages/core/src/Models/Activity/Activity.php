<?php

declare(strict_types=1);

namespace Vaulter\Models\Activity;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Enums\Events\EventType;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Models\Concerns\StampsDriveTenant;

/**
 * An APPEND-ONLY audit/activity row (master plan §2.9 / M2c-activity). ONE row per
 * domain event — recipients are resolved at READ by scoping the feed to a
 * storage/node/actor, never a per-affected-user fan-out (anti-NC-explosion, §1.5).
 *
 * Append-only is structural, not just convention: the table has NO updated_at and
 * NO soft-delete, so {@see self::$timestamps} is OFF and created_at is set on
 * insert by the recorder. There is deliberately no update or delete path.
 *
 * The actor and target are nullable morphs: a console/queue write may have no
 * actor, and a storage-level row has no node target. ip/user_agent/request_id are
 * captured from the current request when one exists and degrade to null off it.
 *
 * Swappable — point configuration key vaulter.models.activity at a subclass for tenant
 * scoping or extra behaviour.
 *
 * @property int|string               $id
 * @property int|string|null          $tenant_id
 * @property int|string|null          $drive_id
 * @property string|null              $actor_type
 * @property int|string|null          $actor_id
 * @property EventType                $verb
 * @property string|null              $target_type
 * @property int|string|null          $target_id
 * @property array<string,mixed>|null $context
 * @property string|null              $ip
 * @property string|null              $user_agent
 * @property string|null              $request_id
 * @property Carbon|null              $created_at
 */
class Activity extends Model
{
    use HasVaulterKeys;
    use ResolvesVaulterConnection;
    use StampsDriveTenant;

    /**
     * Append-only: there is no updated_at column, so Eloquent must not maintain
     * timestamps. created_at is stamped explicitly on insert by the recorder.
     */
    public $timestamps = false;

    /**
     * Fully guarded (F-P1.8-02): an append-only audit row — every column is
     * system-recorded metadata about a domain event, never user input. The sole
     * writer, ActivityStoreRepository, already uses forceFill() exclusively.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('activity');
    }

    /**
     * Newest-first ordering — the canonical feed order (§2.9 (created_at desc)).
     * Every feed query builds on this so the cursor walks back through time.
     *
     * @param  Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'verb' => EventType::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
