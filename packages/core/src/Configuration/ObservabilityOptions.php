<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable observability metrics and tracing configuration (`vaulter.observability`).
 */
#[Api(since: '1.0')]
final readonly class ObservabilityOptions implements OptionsFactory
{
    public function __construct(
        public bool $enabled = false,
        public ?string $recorder = null,
        public TracingOptions $tracing = new TracingOptions,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter.observability'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['enabled', 'recorder', 'tracing'], $path);

        $rawEnabled = $config['enabled'] ?? false;
        $enabled = false;
        if (!is_bool($rawEnabled) && !is_int($rawEnabled) && !is_string($rawEnabled)) {
            $validator->add(path: $path.'.enabled', message: 'must be a boolean');
        } else {
            $enabled = (bool) $rawEnabled;
        }

        $rawRecorder = $config['recorder'] ?? null;
        $recorder = null;
        if ($rawRecorder !== null) {
            if (!is_string($rawRecorder)) {
                $validator->add(path: $path.'.recorder', message: 'must be a class-string or null');
            } elseif ($rawRecorder !== '') {
                $recorder = $rawRecorder;
            }
        }

        $rawTracing = $config['tracing'] ?? [];
        if (!is_array($rawTracing)) {
            $validator->add(path: $path.'.tracing', message: 'must be an array');
            $rawTracing = [];
        }

        $validator->failIfAny();

        return new self(
            enabled: $enabled,
            recorder: $recorder,
            tracing: TracingOptions::fromArray($rawTracing, $path.'.tracing'),
        );
    }
}
