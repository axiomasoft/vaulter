<?php

declare(strict_types=1);

namespace Vaulter\Access\ValueObjects;

use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantLevel;

/**
 * The diagnostic answer to "WHY does (actor, ability, node) resolve the way it
 * does?" (F-P1.1-02 / S1.7). It carries the final verdict, a machine-readable
 * reason, the folded allow/deny masks + effective level, and the provenance chain
 * of every grant the bounded ancestor walk visited (nearest-first).
 *
 * Built ONLY by {@see AccessResolver::explain()} through a separate
 * provenance-collecting pass — it is NEVER produced on the hot can()/effectiveGrants()
 * path, so the diagnostic bookkeeping adds no cost to authorization decisions.
 *
 * @see AccessResolver::explain()
 */
final readonly class AccessExplanation
{
    public const string REASON_SUPER_ALLOW = 'super_override_allow';

    public const string REASON_SUPER_DENY = 'super_override_deny';

    public const string REASON_DENY_VETO = 'deny_veto';

    public const string REASON_ALLOW = 'allow';

    public const string REASON_NO_GRANT = 'no_grant';

    /**
     * @param list<AccessAce> $aces the visited grants, nearest-first
     */
    public function __construct(
        public bool $allowed,
        public Ability $ability,
        public string $reason,
        public int $allowedBits,
        public int $deniedBits,
        public ?GrantLevel $level,
        public array $aces,
    ) {}
}
