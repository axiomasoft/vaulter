<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Schema\AutoIncrementIds;
use Vaulter\Schema\UlidIds;
use Vaulter\Schema\Uuid7Ids;

/**
 * Immutable ID strategy and host-key shape configuration (`vaulter.ids`).
 */
#[Api(since: '1.0')]
final readonly class IdsOptions implements OptionsFactory
{
    /** @var list<'string'|'bigint'|'uuid'|'ulid'> */
    private const array HOST_KEY_TYPES = ['string', 'bigint', 'uuid', 'ulid'];

    /**
     * @param 'string'|'bigint'|'uuid'|'ulid'         $hostKeys
     * @param array<string, class-string<IdStrategy>> $strategies
     */
    public function __construct(
        public string $strategy = 'ulid',
        public string $hostKeys = 'string',
        public array $strategies = [
            'bigint' => AutoIncrementIds::class,
            'ulid' => UlidIds::class,
            'uuid7' => Uuid7Ids::class,
        ],
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter.ids'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['strategy', 'host_keys', 'strategies'], $path);

        $rawStrategy = $config['strategy'] ?? 'ulid';
        $strategy = 'ulid';
        if (!is_string($rawStrategy) || $rawStrategy === '') {
            $validator->add(path: $path.'.strategy', message: 'must be a non-empty strategy key');
        } else {
            $strategy = $rawStrategy;
        }

        $rawHostKeys = $config['host_keys'] ?? 'string';
        /** @var 'string'|'bigint'|'uuid'|'ulid' $hostKeys */
        $hostKeys = 'string';
        if (!is_string($rawHostKeys) || !in_array($rawHostKeys, self::HOST_KEY_TYPES, true)) {
            $label = is_scalar($rawHostKeys) ? (string) $rawHostKeys : get_debug_type($rawHostKeys);
            $validator->add(path: $path.'.host_keys', message: 'unknown ['.$label.']; expected string|bigint|uuid|ulid');
        } else {
            /** @var 'string'|'bigint'|'uuid'|'ulid' $rawHostKeys */
            $hostKeys = $rawHostKeys;
        }

        $rawStrategies = $config['strategies'] ?? [
            'bigint' => AutoIncrementIds::class,
            'ulid' => UlidIds::class,
            'uuid7' => Uuid7Ids::class,
        ];
        /** @var array<string, class-string<IdStrategy>> $strategies */
        $strategies = [];
        if (!is_array($rawStrategies)) {
            $validator->add(path: $path.'.strategies', message: 'must be a map of key => IdStrategy class');
        } else {
            foreach ($rawStrategies as $key => $class) {
                if (!is_string($key) || $key === '' || !is_string($class) || !class_exists($class) || !is_a($class, IdStrategy::class, true)) {
                    $validator->add(path: $path.'.strategies.'.$key, message: 'must name a class implementing '.IdStrategy::class);

                    continue;
                }
                /** @var class-string<IdStrategy> $class */
                $strategies[$key] = $class;
            }
        }

        $validator->failIfAny();

        return new self(
            strategy: $strategy,
            hostKeys: $hostKeys,
            strategies: $strategies,
        );
    }

    public function usesUlids(): bool
    {
        return $this->strategy === 'ulid';
    }
}
