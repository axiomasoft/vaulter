<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Access;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * A capability marker: the {@see PermissionDriver} implementing it declares
 * that its `subjectsFor()` DOES expand an actor into role/group/team {@see Subject}s,
 * not just the actor's own identity — so a `v_node_grants` row addressed to a role or
 * team actually enters the AccessResolver's subject-set and takes effect.
 *
 * This marker is the EXPLICIT capability signal, but not the only one: the built-in
 * base lanes that inherit `subjectsFor()` unchanged (the standalone `database` base,
 * `gate`, `bridge`, the `null` lane) contribute only the actor itself, so a grant
 * addressed to a role/team under them is silently inert. Vaulter makes that silence
 * LOUD — {@see GrantNodeAccessAction} warns (and, outside production, throws) — but ONLY
 * when the driver PROVABLY cannot enumerate: neither this marker NOR an overridden
 * `subjectsFor()` (see {@see PermissionDriverManager::enumeratesRoleSubjects()}).
 * A host that follows the documented extension path — subclass a base lane and override
 * `subjectsFor()` to append role/team Subjects — is recognised WITHOUT this marker.
 *
 * It is a pure marker — no methods — because the fan-out lives in `subjectsFor()`; this
 * interface only ADVERTISES that the driver honours the full docs/adr/0007-pluggable-authorization.md contract
 * (actor → users + roles + teams), sparing the reflection probe. Implementing it is the
 * cleanest way for a host role driver to declare the capability, but overriding
 * `subjectsFor()` alone already suffices for role grants to be accepted.
 */
#[Spi(SpiKind::Implement)]
interface EnumeratesRoleSubjects {}
