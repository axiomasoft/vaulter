<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable route middleware stacks (`vaulter.routes.middleware`).
 */
#[Api(since: '1.0')]
final readonly class RouteMiddlewareOptions implements OptionsFactory
{
    /**
     * @param list<string> $authenticated
     * @param list<string> $public
     * @param list<string> $hub
     */
    public function __construct(
        public array $authenticated = ['api', 'auth:sanctum'],
        public array $public = ['api'],
        public array $hub = ['api'],
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter.routes.middleware'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['authenticated', 'public', 'hub'], $path);

        $authenticated = self::stringList($validator, $path.'.authenticated', $config['authenticated'] ?? ['api', 'auth:sanctum'], ['api', 'auth:sanctum']);
        $public = self::stringList($validator, $path.'.public', $config['public'] ?? ['api'], ['api']);
        $hub = self::stringList($validator, $path.'.hub', $config['hub'] ?? ['api'], ['api']);

        $validator->failIfAny();

        return new self(
            authenticated: $authenticated,
            public: $public,
            hub: $hub,
        );
    }

    /**
     * @param  list<string> $fallback
     * @return list<string>
     */
    private static function stringList(ConfigurationValidator $validator, string $path, mixed $value, array $fallback): array
    {
        if (!is_array($value)) {
            return $fallback;
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                $validator->add(path: $path, message: 'must contain only middleware strings');

                continue;
            }
            $items[] = $item;
        }

        return $items;
    }
}
