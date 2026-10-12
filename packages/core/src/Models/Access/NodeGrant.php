<?php

declare(strict_types=1);

namespace Vaulter\Models\Access;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Vaulter\Access\Roles\Role;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Exceptions\Access\UnknownRoleException;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Models\Concerns\StampsDriveTenant;
use Vaulter\Models\Drive\Node;

/**
 * A positive per-node grant: a subject morph (user|role|team — a registered alias)
 * holds a versioned role on a node, for the node only (item) or also below it
 * (subtree, down to the next inheritance boundary), optionally until expires_at.
 * The AccessResolver unions the roles of every grant that applies with the
 * PermissionDriver's decisions.
 *
 * Swappable — point configuration key vaulter.models.grant at a subclass for tenant scope.
 *
 * @property int|string      $id
 * @property int|string|null $tenant_id
 * @property int|string      $node_id
 * @property string          $subject_type
 * @property int|string      $subject_id
 * @property string          $role_key
 * @property int             $role_version
 * @property GrantScope      $scope
 * @property Carbon|null     $expires_at
 * @property int|string|null $granted_by
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 * @property-read Node $node
 */
class NodeGrant extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;
    use StampsDriveTenant;

    /**
     * Fully guarded: an ACL row — every column (subject, role,
     * scope, expiry, granted_by) is security-
     * critical. The sole writer, GrantStoreRepository, uses forceFill()
     * exclusively and is unaffected by this list.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('node_grants');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(app(VaulterConfig::class)->model('node'), 'node_id');
    }

    /**
     * The grant subject as a value object (the (type, id) the resolver matches on).
     */
    public function subject(): Subject
    {
        return new Subject(type: $this->subject_type, id: $this->subject_id);
    }

    /**
     * The role this grant stores, at its stored version.
     *
     * @throws UnknownRoleException
     */
    public function role(): Role
    {
        return (new RoleRegistry)->get($this->role_key, $this->role_version);
    }

    /**
     * Constrain a query to the grants addressed to any subject in the given set —
     * the "WHERE (subject_type, subject_id) IN (...)" the resolver builds from
     * PermissionDriver::subjectsFor ("shared with me" index).
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
     * enforced read-time, — never a cron). A null expires_at never expires.
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
            'role_version' => 'integer',
            'scope' => GrantScope::class,
            'expires_at' => 'datetime',
        ];
    }
}
