<?php

declare(strict_types=1);

namespace Vaulter\Access;

use Illuminate\Contracts\Container\Container;
use ReflectionMethod;
use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\Drivers\GatePermissionDriver;
use Vaulter\Configuration\AccessConfig;
use Vaulter\Contracts\Access\EnumeratesRoleSubjects;
use Vaulter\Contracts\Access\NonEnumeratingPermissionDriver;
use Vaulter\Contracts\Access\NonProductionPermissionDriver;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Exceptions\Access\MissingPermissionDriverException;

/**
 * Resolves the active {@see PermissionDriver} from config (H.2), keyed by the driver
 * key so a per-panel resolved-state never leaks across panels (H.1). It is the
 * single seam the AccessResolver obtains its role lane through, and the place the
 * null-driver fail-loud guard (D6″) lives: the open NullPermissionDriver is rejected in
 * production, so a missing/null driver can never silently open a prod install.
 *
 * The default key is 'database' — the standalone {@see DatabasePermissionDriver}.
 */
final class PermissionDriverManager
{
    /**
     * The built-in lanes whose subjectsFor() provably contributes ONLY the actor's own
     * identity — no role/team fan-out. A driver that inherits subjectsFor() UNCHANGED
     * from one of these cannot match a v_node_grants row addressed to a role/team.
     *
     * @var list<class-string<PermissionDriver>>
     */
    private const array NON_ENUMERATING_BASE_LANES = [
        DatabasePermissionDriver::class,
        GatePermissionDriver::class,
    ];

    /**
     * @var array<string, PermissionDriver>
     */
    private array $resolved = [];

    public function __construct(
        private readonly AccessConfig $accessConfig,
        private readonly Container $container,
        private readonly bool $isProduction,
    ) {}

    /**
     * The driver for the given key (default: the configured driver). Memoised per
     * key (H.1). Throws in production when the key resolves to the open null
     * driver, or when an unknown key has no FQCN.
     */
    public function driver(?string $key = null): PermissionDriver
    {
        $key ??= $this->accessConfig->permissionDriver();

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $class = $this->classFor($key);

        if (is_a($class, NonProductionPermissionDriver::class, allow_string: true) && $this->isProduction) {
            throw MissingPermissionDriverException::nullDriverInProduction($key);
        }

        /** @var PermissionDriver $driver */
        $driver = $this->container->make($class);

        return $this->resolved[$key] = $driver;
    }

    /**
     * Does the driver expand an actor into role/group/team {@see Subject}s — so a
     * v_node_grants row addressed to a role/team actually enters the AccessResolver
     * subject-set (F-P1.2-01)? Two signals, in order:
     *
     *   1. it implements the explicit {@see EnumeratesRoleSubjects} marker (the spatie
     *      lane, or any host driver that opts in); OR
     *   2. it has OVERRIDDEN subjectsFor() away from a built-in base lane whose
     *      implementation provably contributes only the actor.
     *
     * The second signal keeps the DOCUMENTED extension path honest (H.2 / ADR-0007):
     * {@see DatabasePermissionDriver}'s own docblock promotes "subclass and append
     * Subject(type:'role', …) in subjectsFor()" as the way to add a role lane on the
     * default base. A host that follows it — its role grants genuinely take effect — is
     * recognised WITHOUT also having to add the marker. We therefore fail a role/team
     * grant loud ONLY when the active driver PROVABLY cannot enumerate them (subjectsFor
     * is inherited unchanged from a base lane that returns just [actor]), never for a
     * working-but-unmarked override.
     */
    public function enumeratesRoleSubjects(PermissionDriver $driver): bool
    {
        if ($driver instanceof EnumeratesRoleSubjects) {
            return true;
        }

        $declaration = (new ReflectionMethod($driver, 'subjectsFor'))->getDeclaringClass();

        if (in_array($declaration->getName(), self::NON_ENUMERATING_BASE_LANES, true)) {
            return false;
        }

        // Only the marked base implementation is actor-only. A subclass that
        // overrides subjectsFor() inherits its interfaces but may add role subjects.
        $parent = $declaration->getParentClass();

        return !$declaration->implementsInterface(NonEnumeratingPermissionDriver::class)
            || ($parent !== false && $parent->implementsInterface(NonEnumeratingPermissionDriver::class));
    }

    /**
     * @return class-string<PermissionDriver>
     */
    private function classFor(string $key): string
    {
        $drivers = $this->accessConfig->permissionDrivers();

        if (isset($drivers[$key]) && is_a($drivers[$key], PermissionDriver::class, allow_string: true)) {
            return $drivers[$key];
        }

        throw MissingPermissionDriverException::unknownDriver($key, array_keys($drivers));
    }
}
