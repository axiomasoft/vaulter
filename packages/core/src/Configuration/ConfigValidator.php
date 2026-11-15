<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Events\Outbox\OutboxSchedule;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Idempotency\IdempotencyClaim;
use Vaulter\Idempotency\IdempotencySchedule;

/**
 * Validates the kernel's own `vaulter.*` sections; each module validates its sections itself (the drive through
 * `vaulter.config_validators`, which `vaulter:doctor` repeats).
 */
#[Api]
final readonly class ConfigValidator
{
    #[Internal]
    public function __construct(private Repository $config) {}

    public function validate(VaulterConfig $config, Application $app): void
    {
        foreach ($this->config->all() as $root => $values) {
            if ($root === 'vaulter' || str_starts_with((string) $root, 'vaulter-')) {
                $this->assertNoClosures($values, (string) $root);
            }
        }

        if ($this->config->has('vaulter.features.webhooks')) {
            throw new InvalidConfigurationException('[Vaulter] vaulter.features.webhooks was removed: webhooks are a module, switch them with vaulter.modules.webhooks.');
        }

        if ($config->tenancy()->enabled()) {
            $config->tenancy()->resolver();
            $this->validateTenantResolver($app->make(TenantResolver::class));
        }
        $config->outbox()->validate();
        $config->outboxModel();
        $schedule = new ScheduleConfig($this->config);
        $schedule->enabled();
        // Each module validates its own schedule keys.
        $schedule->frequencies('vaulter', [IdempotencySchedule::KEY, ...OutboxSchedule::KEYS]);
        $ttl = $this->config->get('vaulter.idempotency.ttl_seconds', IdempotencyClaim::DEFAULT_TTL_SECONDS);
        if (!is_int($ttl) || $ttl < 1) {
            throw new InvalidConfigurationException('[Vaulter] vaulter.idempotency.ttl_seconds must be a positive integer (seconds).');
        }
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param class-string<T> $base
     *
     * @phpstan-assert class-string<T> $class
     */
    public static function assertModel(string $key, string $class, string $base): void
    {
        self::assertImplements($key, $class, $base);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $base
     *
     * @phpstan-assert class-string<T> $class
     */
    public static function assertImplements(string $key, string $class, string $base): void
    {
        if ($class === '' || !class_exists($class) || !is_a($class, $base, true)) {
            throw new InvalidConfigurationException('[Vaulter] Config key "'.$key.'" must name a class implementing or extending '.$base.'; got "'.$class.'".');
        }
    }

    private function validateTenantResolver(object $resolver): void
    {
        if (!$resolver instanceof TenantResolver) {
            throw new TenancyMisconfiguredException('TenantResolver binding returned an invalid object.');
        }
    }

    private function assertNoClosures(mixed $value, string $key): void
    {
        if ($value instanceof Closure) {
            throw new InvalidConfigurationException('[Vaulter] Config key "'.$key.'" contains a Closure; use a FQCN or scalar value.');
        }
        if (is_array($value)) {
            foreach ($value as $name => $item) {
                $this->assertNoClosures($item, $key.'.'.$name);
            }
        }
    }
}
