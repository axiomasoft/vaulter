<?php

declare(strict_types=1);

namespace Vaulter\Doctor;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Kernel;

/**
 * The checks the active modules contribute to the DoctorCheck slot, ordered by key; the contribution key
 * is the check's own key. Checks a host tags `vaulter.doctor_checks` join them (the packages declare
 * theirs in their manifests).
 */
#[Internal]
final readonly class DoctorRegistry
{
    public const string TAG = 'vaulter.doctor_checks';

    public function __construct(private Container $container, private ?Kernel $kernel = null) {}

    /**
     * @throws LogicException when two checks share a key or a tagged service is not a check
     *
     * @return list<DoctorCheck>
     */
    public function all(): array
    {
        $checks = [];
        foreach ($this->contributed() as $check) {
            $checks[$check->key()] = $check;
        }
        foreach ($this->container->tagged(self::TAG) as $check) {
            if (!$check instanceof DoctorCheck) {
                throw new LogicException(sprintf('Service tagged "%s" must implement %s; got %s.', self::TAG, DoctorCheck::class, get_debug_type($check)));
            }
            if (isset($checks[$check->key()])) {
                throw new LogicException(sprintf('Doctor check key "%s" is registered twice.', $check->key()));
            }
            $checks[$check->key()] = $check;
        }
        ksort($checks, SORT_STRING);

        return array_values($checks);
    }

    /**
     * @param  list<string>      $prefixes empty selects every check
     * @return list<DoctorCheck>
     */
    public function matching(array $prefixes): array
    {
        $checks = $this->all();
        if ($prefixes === []) {
            return $checks;
        }

        return array_values(array_filter($checks, static function (DoctorCheck $check) use ($prefixes): bool {
            foreach ($prefixes as $prefix) {
                if (self::matches($check->key(), $prefix)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param  list<string> $prefixes
     * @return list<string> the prefixes that select no check
     */
    public function unmatched(array $prefixes): array
    {
        $keys = array_map(static fn (DoctorCheck $check): string => $check->key(), $this->all());

        return array_values(array_filter($prefixes, static function (string $prefix) use ($keys): bool {
            foreach ($keys as $key) {
                if (self::matches($key, $prefix)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @throws LogicException when a contribution is not a check or reports another key than it is declared under
     *
     * @return list<DoctorCheck>
     */
    private function contributed(): array
    {
        if ($this->kernel === null || !$this->kernel->isValid()) {
            return [];
        }
        $checks = [];
        foreach ($this->kernel->extensions()->contributions(DoctorCheck::class) as $contribution) {
            $check = $this->container->make($contribution->implementation);
            if (!$check instanceof DoctorCheck) {
                throw new LogicException(sprintf('Doctor check "%s" of module "%s" must implement %s; got %s.', $contribution->id, $contribution->module, DoctorCheck::class, get_debug_type($check)));
            }
            if ($check->key() !== $contribution->key) {
                throw new LogicException(sprintf('Doctor check %s is declared under the key "%s" but reports "%s".', $contribution->implementation, (string) $contribution->key, $check->key()));
            }
            $checks[] = $check;
        }

        return $checks;
    }

    /** A prefix selects whole key segments: `core.schema` selects `core.schema.tables`, not `core.schemaX`. */
    private static function matches(string $key, string $prefix): bool
    {
        return $key === $prefix || str_starts_with($key, $prefix.'.');
    }
}
