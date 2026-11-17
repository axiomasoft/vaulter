<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable accepted-risk configuration (`vaulter.security`).
 */
#[Api(since: '1.0')]
final readonly class SecurityOptions implements OptionsFactory
{
    /** @param array<string, mixed> $acceptedRisks */
    public function __construct(
        public array $acceptedRisks = [],
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter.security'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['accepted_risks'], $path);

        $rawRisks = $config['accepted_risks'] ?? [];
        $acceptedRisks = [];
        if (!is_array($rawRisks)) {
            $validator->add(path: $path.'.accepted_risks', message: 'must be an array mapping invariant id to reason');
        } else {
            foreach ($rawRisks as $id => $reason) {
                if (!is_string($id) || $id === '') {
                    $validator->add(path: $path.'.accepted_risks', message: 'keys must be non-empty invariant ids');

                    continue;
                }
                $acceptedRisks[$id] = $reason;
            }
        }

        $validator->failIfAny();

        return new self(acceptedRisks: $acceptedRisks);
    }
}
