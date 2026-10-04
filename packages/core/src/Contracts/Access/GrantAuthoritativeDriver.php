<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Access;

/**
 * A capability a {@see PermissionDriver} implements to declare that v_node_grants is the
 * SOLE authority for read-visibility (master plan P1 #3 / H.4). When {@see
 * isGrantAuthoritative()} is true, the grant-anchor narrowing in {@see
 * NodeReadRepository::constrainToAccessible()} is a COMPLETE superset of everything the
 * actor can reach, so narrowing a listing to that set never wrongly hides a row.
 *
 * Only the standalone {@see DatabasePermissionDriver} declares it true out of the box.
 * For the gate/spatie/azgard drivers the host's roles govern (an actor can see nodes with
 * NO grant anchor), so they do NOT implement this interface at all ⇒ the listing stays
 * OPEN (the safe default, never wrongly hiding).
 *
 * This is deliberately a SEPARATE capability, not a method on the SemVer-frozen
 * {@see PermissionDriver} (adding to that contract would break every external driver). A
 * driver opts IN by implementing this; its absence is the conservative, BC-safe default.
 *
 * It is a METHOD, not a bare marker, on purpose: PHP interfaces are INHERITED, so a host
 * that SUBCLASSES {@see DatabasePermissionDriver} to add a role lane (resolve()) or a
 * super-admin override() still carries the interface — declaring or not declaring it on
 * the subclass changes nothing. Such a subclass MUST override {@see isGrantAuthoritative()}
 * to return false (it can now reach un-anchored nodes, so narrowing would hide them). A
 * pure subject-broadening subclass that introduces no new authority keeps returning true.
 */
interface GrantAuthoritativeDriver
{
    /**
     * Whether v_node_grants is THIS driver instance's sole read-visibility authority, so a
     * listing may safely narrow to the actor's grant-reachable candidate set. Return false
     * the moment the driver confers access by ANY non-grant means (host roles/teams, a
     * workspace-create gate, a super-admin override()).
     */
    public function isGrantAuthoritative(): bool;
}
