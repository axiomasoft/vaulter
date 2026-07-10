<?php

declare(strict_types=1);

namespace Vaulter\Access;

use Illuminate\Contracts\Container\Container;
use ReflectionMethod;
use Vaulter\Contracts\Access\EnumeratesRoleSubjects;
use Vaulter\Contracts\Access\HostRoleDriver;
use Vaulter\Drivers\Permission\AzgardPermissionResolver;
use Vaulter\Drivers\Permission\DatabasePermissionResolver;
use Vaulter\Drivers\Permission\GatePermissionResolver;
use Vaulter\Exceptions\Access\MissingHostRoleDriverException;
use Vaulter\Support\Config;
use Vaulter\Testing\NullRoleDriver;

/**
 * Resolves the active {@see HostRoleDriver} from config (H.2), keyed by the driver
 * key so a per-panel resolved-state never leaks across panels (H.1). It is the
 * single seam the AccessResolver obtains its role lane through, and the place the
 * null-driver fail-loud guard (D6″) lives: the open NullRoleDriver is rejected in
 * production, so a missing/null driver can never silently open a prod install.
 *
 * The default key is 'database' — the standalone {@see DatabasePermissionResolver}.
 */
final class HostRoleDriverManager
{
    /**
     * The built-in lanes whose subjectsFor() provably contributes ONLY the actor's own
     * identity — no role/team fan-out. A driver that inherits subjectsFor() UNCHANGED
     * from one of these cannot match a v_node_grants row addressed to a role/team.
     *
     * @var list<class-string<HostRoleDriver>>
     */
    private const array NON_ENUMERATING_BASE_LANES = [
        DatabasePermissionResolver::class,
        GatePermissionResolver::class,
        AzgardPermissionResolver::class,
        NullRoleDriver::class,
    ];

    /**
     * @var array<string, HostRoleDriver>
     */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
        private readonly bool $isProduction,
    ) {}

    /**
     * The driver for the given key (default: the configured driver). Memoised per
     * key (H.1). Throws in production when the key resolves to the open null
     * driver, or when an unknown key has no FQCN.
     */
    public function driver(?string $key = null): HostRoleDriver
    {
        $key ??= Config::permissionDriver();

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $class = $this->classFor($key);

        if (is_a($class, NullRoleDriver::class, allow_string: true) && $this->isProduction) {
            throw MissingHostRoleDriverException::nullDriverInProduction($key);
        }

        /** @var HostRoleDriver $driver */
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
     * {@see DatabasePermissionResolver}'s own docblock promotes "subclass and append
     * Subject(type:'role', …) in subjectsFor()" as the way to add a role lane on the
     * default base. A host that follows it — its role grants genuinely take effect — is
     * recognised WITHOUT also having to add the marker. We therefore fail a role/team
     * grant loud ONLY when the active driver PROVABLY cannot enumerate them (subjectsFor
     * is inherited unchanged from a base lane that returns just [actor]), never for a
     * working-but-unmarked override.
     */
    public function enumeratesRoleSubjects(HostRoleDriver $driver): bool
    {
        if ($driver instanceof EnumeratesRoleSubjects) {
            return true;
        }

        $declaringClass = (new ReflectionMethod($driver, 'subjectsFor'))
            ->getDeclaringClass()
            ->getName();

        return !in_array($declaringClass, self::NON_ENUMERATING_BASE_LANES, true);
    }

    /**
     * @return class-string<HostRoleDriver>
     */
    private function classFor(string $key): string
    {
        $drivers = Config::permissionDrivers();

        if (isset($drivers[$key])) {
            return $drivers[$key];
        }

        throw MissingHostRoleDriverException::unknownDriver($key, array_keys($drivers));
    }
}
