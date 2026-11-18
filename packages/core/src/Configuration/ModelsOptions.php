<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Events\Outbox\Models\OutboxEvent;

/**
 * Immutable swappable model configuration (`vaulter.models`).
 */
#[Api(since: '1.0')]
final readonly class ModelsOptions implements OptionsFactory
{
    /** @param class-string<OutboxEvent> $outbox */
    #[Internal]
    public function __construct(
        #[Internal]
        public string $outbox = OutboxEvent::class,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter.models'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['outbox'], $path);

        $rawOutbox = $config['outbox'] ?? OutboxEvent::class;
        /** @var class-string<OutboxEvent> $outbox */
        $outbox = OutboxEvent::class;
        if (!is_string($rawOutbox) || $rawOutbox === '' || !class_exists($rawOutbox) || !is_a($rawOutbox, OutboxEvent::class, true)) {
            $validator->add(path: $path.'.outbox', message: 'must name a class extending '.OutboxEvent::class);
        } else {
            /** @var class-string<OutboxEvent> $rawOutbox */
            $outbox = $rawOutbox;
        }

        $validator->failIfAny();

        return new self(outbox: $outbox);
    }
}
