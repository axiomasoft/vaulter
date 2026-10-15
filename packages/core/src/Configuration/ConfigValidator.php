<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Vaulter\Access\PermissionDriverCatalog;
use Vaulter\Contracts\Access\NonProductionPermissionDriver;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Events\Outbox\OutboxSchedule;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Exceptions\Profiles\InvalidProfileException;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Idempotency\IdempotencyClaim;
use Vaulter\Idempotency\IdempotencySchedule;
use Vaulter\Kernel;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Webhooks\WebhooksSchedule;

final readonly class ConfigValidator
{
    public function __construct(private Repository $config) {}

    public function validate(VaulterConfig $config, Application $app): void
    {
        foreach ($this->config->all() as $root => $values) {
            if ($root === 'vaulter' || str_starts_with((string) $root, 'vaulter-')) {
                $this->assertNoClosures($values, (string) $root);
            }
        }

        if ($this->config->has('vaulter.features.webhooks')) {
            throw new InvalidConfigurationException('[Vaulter] vaulter.features.webhooks was removed: webhooks are a module, switch them with vaulter.modules.webhooks (D22).');
        }

        if ($config->tenancy()->enabled()) {
            $config->tenancy()->resolver();
            $this->validateTenantResolver($app->make(TenantResolver::class));
        }
        // These accessors validate the untrusted class strings before returning them.
        $config->webhooks()->assertNoRemovedKeys();
        $config->webhooks()->urlGuard();
        $config->webhooks()->retrySchedule();
        $config->webhooks()->retryJitter();
        $config->webhooks()->relayFanoutChunk();
        $config->outbox()->validate();
        $schedule = new ScheduleConfig($this->config);
        $schedule->enabled();
        // changes_prune is the drive's core-located key (D39); the webhooks keys belong to WebhooksModule.
        $schedule->frequencies('vaulter', ['changes_prune', IdempotencySchedule::KEY, ...OutboxSchedule::KEYS, ...WebhooksSchedule::KEYS]);
        $ttl = $this->config->get('vaulter.idempotency.ttl_seconds', IdempotencyClaim::DEFAULT_TTL_SECONDS);
        if (!is_int($ttl) || $ttl < 1) {
            throw new InvalidConfigurationException('[Vaulter] vaulter.idempotency.ttl_seconds must be a positive integer (seconds).');
        }

        // The drive sections located in the core config are its active path (D39): validated only while it is active.
        if ($app->make(Kernel::class)->isActive('drive')) {
            $this->validateDrive($config, $app);
        }
    }

    private function validateDrive(VaulterConfig $config, Application $app): void
    {
        $access = $config->access();
        $key = $access->permissionDriver();
        $drivers = (new PermissionDriverCatalog($access, $app->make(Kernel::class)))->classes();
        if (!isset($drivers[$key])) {
            throw new InvalidConfigurationException('[Vaulter] Unknown vaulter.access.permission_driver "'.$key.'".');
        }
        self::assertImplements('vaulter.access.permission_drivers.'.$key, $drivers[$key], PermissionDriver::class);
        if ($app->environment('production') && is_a($drivers[$key], NonProductionPermissionDriver::class, true)) {
            throw new InvalidConfigurationException('[Vaulter] Non-production permission driver "'.$key.'" is forbidden in production.');
        }
        $config->changes()->retentionDays();
        $config->changes()->maxPageSize();
        $config->sharing()->tokenGenerator();
        foreach ([
            'drive',
            'node',
            'file',
            'scan_obligation', 'scan_request', 'scan_evidence', 'scan_policy_state', 'scan_backfill_run',
            'file_revision',
            'file_revision_blob',
            'blob',
            'grant',
            'node_share',
            'activity',
            'outbox',
            'webhook_endpoint',
            'webhook_delivery',
        ] as $key) {
            $config->model($key);
        }

        try {
            if ($app->resolved(ProfileRegistry::class)) {
                $registry = $app->make(ProfileRegistry::class);
                // Standalone validation may use a different Repository than the booted app.
                if ($config !== $app->make(VaulterConfig::class)) {
                    $registry = ProfileRegistry::fromConfig($config->profileProviders(), $config->defaultProfile(), $app);
                }
            } else {
                $registry = ProfileRegistry::fromConfig($config->profileProviders(), $config->defaultProfile(), $app);
                $app->instance(ProfileRegistry::class, $registry);
            }
            $policy = new ProfilePolicy($config, $this->config, $registry, new ScanConfig($this->config), $app->make(Kernel::class));
            foreach ($registry->all() as $profile) {
                $policy->validate($profile);
                $profileKey = $profile->getPermissionDriver() ?? $access->permissionDriver();
                self::assertImplements('vaulter.access.permission_drivers.'.$profileKey, $drivers[$profileKey] ?? '', PermissionDriver::class);
                if ($app->environment('production') && is_a($drivers[$profileKey], NonProductionPermissionDriver::class, true)) {
                    throw new InvalidConfigurationException('[Vaulter] Non-production profile permission driver "'.$profileKey.'" is forbidden in production.');
                }
            }
            (new ScanConfig($this->config))->scanner();
        } catch (InvalidProfileException $exception) {
            throw new InvalidConfigurationException($exception->getMessage(), previous: $exception);
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

    public static function assertCollaborationSecrets(bool $enabled, ?string $jwtSecret, ?string $webhookSecret, Application $app): void
    {
        if (!$enabled || ($jwtSecret !== null && $jwtSecret !== '' && $webhookSecret !== null && $webhookSecret !== '' && $jwtSecret !== $webhookSecret)) {
            return;
        }
        $message = '[Vaulter] Collaboration requires nonempty, different JWT and webhook secrets.';
        if ($app->environment('production')) {
            throw new InvalidConfigurationException($message);
        }
        Log::warning($message);
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
