<?php

declare(strict_types=1);

namespace Vaulter\Tenant;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Foundation\Application;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\ServiceProvider;
use Tenant\Contracts\ContextCodec;
use Tenant\Contracts\TenantRuntime;
use Tenant\Data\IsolationIdentity;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Contracts\ResourceStorage;
use Tenant\Laravel\Queue\ContextDispatcher;
use Tenant\Laravel\Queue\QueueRegistry;
use Vaulter\Contracts\Jobs\JobPublisher;
use Vaulter\Contracts\Tenancy\ResolvesOwnerTenant;
use Vaulter\Jobs\JobRegistry;
use Vaulter\Tenant\Contracts\OwnerMapping;
use Vaulter\Tenant\Jobs\TenantJobPublisher;
use Vaulter\Tenant\Storage\ResourceDisk;

/** Explicit host composition; supplies adapters to one pre-existing public runtime. */
final class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/vaulter-tenant.php', 'vaulter-tenant');
        $this->app->make('filesystem')->extend('vaulter-tenant', function ($app) {
            $storage = $app->make('tenant.storage');
            if (!$storage instanceof ResourceStorage) {
                throw new \LogicException('Public resource storage is unavailable.');
            }

            return new ResourceDisk($storage->disk());
        });
        $this->app->singleton(ScalarProjection::class, fn ($app) => new ScalarProjection($app['config']->get('vaulter-tenant.namespace')));
        $this->app->singleton(TenantResolver::class);
        $this->app->bind(ResolvesOwnerTenant::class, fn ($app) => $app->make(TenantResolver::class));
        $this->app->singleton(TenantJobPublisher::class, function ($app): TenantJobPublisher {
            $locks = $app['cache']->store('central')->getStore();
            if (!$locks instanceof LockProvider) {
                throw new \InvalidArgumentException('Central cache must support owned locks.');
            }

            return new TenantJobPublisher(
                $app->make(TenantRuntime::class), $app->make(ContextCodec::class),
                $app->make(ContextDispatcher::class), $app->make(QueueRegistry::class), $app->make(JobRegistry::class),
                $locks, $app->make(Dispatcher::class),
                new Pipeline($app), $app->make(ScalarProjection::class),
                $app['config']->get('vaulter-tenant.queues'), $app['config']->get('vaulter-tenant.connection'),
                $app['config']->get('vaulter-tenant.lock_seconds'),
            );
        });
        $this->app->singleton(JobPublisher::class, fn ($app) => $app->make(TenantJobPublisher::class));
    }

    public function boot(): void
    {
        $config = $this->app['config'];
        if ($config->get('vaulter.tenancy.enabled') !== true || $config->get('vaulter.tenancy.resolver') !== TenantResolver::class
            || !$this->app->bound(QueueRegistry::class) || !$this->app->bound(OwnerMapping::class)) {
            throw new \InvalidArgumentException('Vaulter Tenant requires strict tenancy, configured resolver and explicit host mapping/queue registry.');
        }
        $registry = $this->app->make(QueueRegistry::class);
        $fixed = $config->get('vaulter-tenant.namespace');
        if ($registry->namespaces === [] || ($fixed !== null && $registry->namespaces !== [$fixed])) {
            throw new \InvalidArgumentException('Fixed namespace composition must allow exactly its one namespace.');
        }
        foreach ($registry->namespaces as $namespace) {
            $this->app->make(ScalarProjection::class)->encode(new IsolationIdentity($namespace, 'x'));
        }
        $this->app->make(SchemaGuard::class)->verify();
        $resolver = $this->app->make(TenantResolver::class);
        if ($this->app->make(\Vaulter\Contracts\Tenancy\TenantResolver::class) !== $resolver
            || $this->app->make(ResolvesOwnerTenant::class) !== $resolver) {
            throw new TenantFailure('composition_frozen');
        }
        $container = $this->app;
        if (!$container instanceof Application) {
            throw new \InvalidArgumentException('Laravel application is required.');
        }
        // Resolve and freeze after all providers registered; late mutation is rejected and restored.
        foreach ([TenantResolver::class, \Vaulter\Contracts\Tenancy\TenantResolver::class, ResolvesOwnerTenant::class, JobPublisher::class, JobRegistry::class] as $key) {
            $owner = $this->app->make($key);
            $container->rebinding($key, function ($app, $replacement) use ($key, $owner): void {
                if ($replacement === $owner) {
                    return;
                }
                $app->instance($key, $owner);
                throw new TenantFailure('composition_frozen');
            });
        }
    }
}
