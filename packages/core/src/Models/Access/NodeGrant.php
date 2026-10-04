<?php

declare(strict_types=1);

namespace Vaulter\Models\Access;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Models\Concerns\HasExtraData;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

/**
 * A per-node ACL grant with inheritance (master plan §2.6). A grant addresses a
 * subject morph (user|role|team — a registered alias) on a node with a level +
 * CRUDS bitmask, an allow|deny effect, an item|subtree scope and an optional
 * expiry. The AccessResolver walks ancestors collecting these (direct + subtree)
 * for an actor's subject-set and folds them with the PermissionDriver.
 *
 * `inherited`/`inherited_from_node_id` are reserved for an OPTIONAL materialised
 * effective-grants read-model; the source of truth in M2a is the direct rows plus
 * the tree, so they stay false/null here.
 *
 * Swappable — point config('vaulter.models.grant') at a subclass for tenant scope.
 *
 * @property int|string      $id
 * @property int|string|null $tenant_id
 * @property int|string      $node_id
 * @property string          $subject_type
 * @property int|string      $subject_id
 * @property GrantLevel      $level
 * @property int             $permissions
 * @property Effect          $effect
 * @property GrantScope      $scope
 * @property Carbon|null     $expires_at
 * @property int|string|null $granted_by
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 * @property-read Node $node
 */
class NodeGrant extends Model
{
    use HasExtraData;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    /**
     * Fully guarded (F-P1.8-02): an ACL row — every column (subject, level,
     * permissions bitmask, effect, scope, expiry, granted_by) is security-
     * critical. The sole writer, GrantStoreRepository, uses forceFill()
     * exclusively and is unaffected by this list.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('node_grants');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Config::nodeModel(), 'node_id');
    }

    /**
     * The grant subject as a value object (the (type, id) the resolver matches on).
     */
    public function subject(): Subject
    {
        return new Subject(type: $this->subject_type, id: $this->subject_id);
    }

    /**
     * Constrain a query to the grants addressed to any subject in the given set —
     * the "WHERE (subject_type, subject_id) IN (...)" the resolver builds from
     * PermissionDriver::subjectsFor (§2.6 "shared with me" index).
     *
     * @param  Builder<$this> $query
     * @param  list<Subject>  $subjects
     * @return Builder<$this>
     */
    public function scopeForSubjects(Builder $query, array $subjects): Builder
    {
        if ($subjects === []) {
            // No subjects ⇒ no grants can apply; force an empty result.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(static function (Builder $inner) use ($subjects): void {
            foreach ($subjects as $subject) {
                $inner->orWhere(static function (Builder $pair) use ($subject): void {
                    $pair->where('subject_type', $subject->type)
                        ->where('subject_id', $subject->id);
                });
            }
        });
    }

    /**
     * Constrain a query to grants that have not expired as of $now (expiry is
     * enforced read-time, H.2 — never a cron). A null expires_at never expires.
     *
     * @param  Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeNotExpired(Builder $query, Carbon $now): Builder
    {
        return $query->where(static function (Builder $inner) use ($now): void {
            $inner->whereNull('expires_at')->orWhere('expires_at', '>', $now);
        });
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'level' => GrantLevel::class,
            'effect' => Effect::class,
            'scope' => GrantScope::class,
            'permissions' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
