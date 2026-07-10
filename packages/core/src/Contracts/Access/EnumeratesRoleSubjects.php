<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Access;

use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Actions\Access\GrantNodeAccessAction;

/**
 * A capability marker (F-P1.2-01): the {@see HostRoleDriver} implementing it declares
 * that its `subjectsFor()` DOES expand an actor into role/group/team {@see Subject}s,
 * not just the actor's own identity — so a `v_node_grants` row addressed to a role or
 * team actually enters the AccessResolver's subject-set and takes effect.
 *
 * Drivers WITHOUT this marker (the standalone `database` base, `gate`, `azgard`, the
 * `null` lane) only ever contribute the actor itself; a grant addressed to a role/team
 * under them is silently inert. Vaulter uses this marker to make that silence LOUD:
 * {@see GrantNodeAccessAction} warns (and, outside production,
 * throws) when a role/team grant is written under a driver that lacks this capability.
 *
 * It is a pure marker — no methods — because the fan-out lives in `subjectsFor()`; this
 * interface only advertises that the driver honours the full ADR-0007 contract
 * (actor → users + roles + teams). A host driver that appends role/team Subjects should
 * implement it so its role grants are accepted without a warning.
 */
interface EnumeratesRoleSubjects {}
