<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

/** Typed accessors over the `schedule` blocks of the Vaulter config roots. */
#[Api]
final readonly class ScheduleConfig
{
    #[Internal]
    public function __construct(private Repository $config) {}

    /** Whether the packages put their maintenance tasks on the host scheduler at all. */
    public function enabled(): bool
    {
        $enabled = $this->config->get('vaulter.schedule.enabled', true);
        if (!is_bool($enabled)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter.schedule.enabled must be a boolean.');
        }

        return $enabled;
    }

    /**
     * @param list<string> $keys
     *
     * @throws InvalidConfigurationException
     *
     * @return array<string, ?ScheduleFrequency> a key missing here was already filled with the package default by the normalizer; null turns a task off
     */
    public function frequencies(string $root, array $keys): array
    {
        $path = $root.'.schedule';
        $values = $this->config->get($path, []);
        if (!is_array($values)) {
            throw new InvalidConfigurationException('[Vaulter] '.$path.' must be an array.');
        }

        $frequencies = [];
        foreach ($keys as $key) {
            $frequencies[$key] = ScheduleFrequency::parse($path.'.'.$key, $values[$key] ?? null);
        }

        return $frequencies;
    }
}
