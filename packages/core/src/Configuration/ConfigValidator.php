<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Vaulter\Contracts\Access\NonProductionPermissionDriver;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Profiles\ProfileProvider;

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

        $access = $config->access();
        $key = $access->permissionDriver();
        $drivers = $access->permissionDrivers();
        if (!isset($drivers[$key])) {
            throw new InvalidConfigurationException('[Vaulter] Unknown vaulter.access.permission_driver "'.$key.'".');
        }
        self::assertImplements('vaulter.access.permission_drivers.'.$key, $drivers[$key], PermissionDriver::class);
        if ($app->environment('production') && is_a($drivers[$key], NonProductionPermissionDriver::class, true)) {
            throw new InvalidConfigurationException('[Vaulter] Non-production permission driver "'.$key.'" is forbidden in production.');
        }
        // These accessors validate the untrusted class strings before returning them.
        $config->webhooks()->urlGuard();
        $config->sharing()->tokenGenerator();
        foreach ([
            'drive',
            'node',
            'file',
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

        $classes = [$config->defaultProfile(), ...$config->profileProviders()];
        $ids = [];
        foreach ($classes as $class) {
            $provider = $app->make($class);
            if (!$provider instanceof ProfileProvider) {
                throw new InvalidConfigurationException('[Vaulter] Container binding for profile provider "'.$class.'" must return a ProfileProvider.');
            }
            $id = $provider->resolve()->getId();
            if ($id === '' || isset($ids[$id])) {
                throw new InvalidConfigurationException('[Vaulter] Profile keys must be nonempty and unique; duplicate "'.$id.'".');
            }
            $ids[$id] = true;
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
