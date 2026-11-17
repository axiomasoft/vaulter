<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable kernel maintenance scheduling configuration (`vaulter.schedule`).
 */
#[Api(since: '1.0')]
final readonly class ScheduleOptions implements OptionsFactory
{
    public function __construct(
        public bool $enabled = true,
        public ?string $outboxRelay = 'everyMinute',
        public ?string $outboxPrune = 'hourly',
        public ?string $idempotencyPrune = 'dailyAt:03:30',
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter.schedule'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['enabled', 'outbox_relay', 'outbox_prune', 'idempotency_prune'], $path);

        $rawEnabled = $config['enabled'] ?? true;
        $enabled = true;
        if (!is_bool($rawEnabled)) {
            $validator->add(path: $path.'.enabled', message: 'must be a boolean');
        } else {
            $enabled = $rawEnabled;
        }

        $outboxRelay = self::validatedFrequency($validator, $path, 'outbox_relay', array_key_exists('outbox_relay', $config) ? $config['outbox_relay'] : 'everyMinute');
        $outboxPrune = self::validatedFrequency($validator, $path, 'outbox_prune', array_key_exists('outbox_prune', $config) ? $config['outbox_prune'] : 'hourly');
        $idempotencyPrune = self::validatedFrequency($validator, $path, 'idempotency_prune', array_key_exists('idempotency_prune', $config) ? $config['idempotency_prune'] : 'dailyAt:03:30');

        $validator->failIfAny();

        return new self(
            enabled: $enabled,
            outboxRelay: $outboxRelay,
            outboxPrune: $outboxPrune,
            idempotencyPrune: $idempotencyPrune,
        );
    }

    public function frequency(string $key): ?ScheduleFrequency
    {
        $raw = match ($key) {
            'outbox_relay' => $this->outboxRelay,
            'outbox_prune' => $this->outboxPrune,
            'idempotency_prune' => $this->idempotencyPrune,
            default => null,
        };

        return ScheduleFrequency::parse('vaulter.schedule.'.$key, $raw);
    }

    private static function validatedFrequency(ConfigurationValidator $validator, string $basePath, string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            ScheduleFrequency::parse($basePath.'.'.$key, $value);
        } catch (InvalidConfiguration $exception) {
            $validator->add(path: $basePath.'.'.$key, message: $exception->getMessage());

            return null;
        }

        return is_string($value) ? $value : null;
    }
}
