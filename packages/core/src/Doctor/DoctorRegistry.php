<?php

declare(strict_types=1);

namespace Vaulter\Doctor;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Vaulter\Contracts\Doctor\DoctorCheck;

/** The checks every installed package tagged `vaulter.doctor_checks`, ordered by key. */
final readonly class DoctorRegistry
{
    public const string TAG = 'vaulter.doctor_checks';

    public function __construct(private Container $container) {}

    /**
     * @throws LogicException when two checks share a key or a tagged service is not a check
     *
     * @return list<DoctorCheck>
     */
    public function all(): array
    {
        $checks = [];
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

    /** A prefix selects whole key segments: `core.schema` selects `core.schema.tables`, not `core.schemaX`. */
    private static function matches(string $key, string $prefix): bool
    {
        return $key === $prefix || str_starts_with($key, $prefix.'.');
    }
}
