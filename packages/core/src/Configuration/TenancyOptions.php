<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;

/**
 * Immutable tenancy boundary configuration (`vaulter.tenancy`).
 */
#[Api(since: '1.0')]
final readonly class TenancyOptions implements OptionsFactory
{
    public const string COLUMN = 'tenant_id';

    public function __construct(
        public bool $enabled = false,
        public ?string $resolver = null,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter.tenancy'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['enabled', 'resolver'], $path);

        $rawEnabled = $config['enabled'] ?? false;
        $enabled = false;
        if (!is_bool($rawEnabled)) {
            $validator->add(path: $path.'.enabled', message: 'must be a boolean');
        } else {
            $enabled = $rawEnabled;
        }

        $rawResolver = $config['resolver'] ?? null;
        $resolver = null;
        if ($rawResolver !== null) {
            if (!is_string($rawResolver)) {
                $validator->add(path: $path.'.resolver', message: 'must be a TenantResolver class-string or null');
            } elseif ($rawResolver !== '') {
                $resolver = $rawResolver;
            }
        }

        $validator->failIfAny();

        return new self(enabled: $enabled, resolver: $resolver);
    }

    /** @return class-string<TenantResolver> */
    public function requireResolver(): string
    {
        if (!is_string($this->resolver) || !class_exists($this->resolver) || !is_a($this->resolver, TenantResolver::class, true)) {
            throw new TenancyMisconfiguredException('vaulter.tenancy.resolver must implement TenantResolver when strict tenancy is enabled.');
        }

        /** @var class-string<TenantResolver> $class */
        $class = $this->resolver;

        return $class;
    }
}
