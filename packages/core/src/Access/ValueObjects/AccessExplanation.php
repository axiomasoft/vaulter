<?php

declare(strict_types=1);

namespace Vaulter\Access\ValueObjects;

use Vaulter\Attributes\Api;
use Vaulter\Enums\Access\Ability;

/**
 * Why (subject, ability, node) resolves the way it does: the verdict, a stable
 * reason code, every role that applies (with where it comes from), the
 * administrative restrictions in force, and the inheritance boundary that stopped
 * grants from further up, if any.
 *
 * Built only by AccessResolver::explain(), never on the can() path.
 */
#[Api]
final readonly class AccessExplanation
{
    public const string REASON_ALLOW = 'allow';

    public const string REASON_NO_GRANT = 'no_grant';

    public const string REASON_OVERRIDE_ALLOW = 'override_allow';

    public const string REASON_OVERRIDE_DENY = 'override_deny';

    /** An explicit system actor: the ACL does not apply. */
    public const string REASON_SYSTEM = 'system';

    /** The restriction code of a PermissionDriver::override() Deny. */
    public const string RESTRICTION_OVERRIDE_DENY = 'administrative_block';

    /**
     * @param list<AccessSource> $sources      nearest first; the driver's roles last
     * @param list<string>       $restrictions
     */
    public function __construct(
        public bool $allowed,
        public Ability $ability,
        public string $reason,
        public array $sources,
        public array $restrictions,
        public int|string|null $boundaryNodeId,
    ) {}
}
