<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Kernel;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleDeclarations;
use Vaulter\Modules\SlotContribution;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Schema\WebhooksSchema;
use Vaulter\Webhooks\VaulterWebhooksServiceProvider;
use Vaulter\Webhooks\WebhookFanoutConsumer;
use Vaulter\Webhooks\WebhooksModule;
use Vaulter\Webhooks\WebhooksProblemCodes;

/** @return list<string> the names of the routes of the webhooks REST surface that the application registers */
function webhookRouteNames(Application $app): array
{
    $names = [];
    foreach ($app['router']->getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (str_starts_with($name, 'vaulter.v1.webhook-')) {
            $names[] = $name;
        }
    }
    sort($names);

    return $names;
}

it('declares the webhooks module: opt-in, over the core and the drive, owner of its two tables', function (): void {
    $module = new WebhooksModule;

    expect($module->manifest()->id)->toBe('vaulter.webhooks')
        ->and($module->moduleRuntime()->activation)->toBe(Activation::OptIn)
        ->and($module->manifest()->requires)->toBe(['vaulter.core', 'vaulter.drive'])
        ->and($module->schemaComponents()[0])->toBe(WebhooksSchema::class);
});

it('declares everything of the package through its manifest, paths relative to the package', function (): void {
    $module = ModuleDeclarations::buildOffered(WebhooksModule::class);
    $package = dirname(__DIR__, 2);

    expect(array_map(realpath(...), $module->configs()))->toBe(['vaulter-webhooks' => realpath($package.'/config/vaulter-webhooks.php')])
        ->and(array_map(realpath(...), $module->routeFiles()))->toBe([realpath($package.'/routes/webhooks.php')])
        ->and(array_map(realpath(...), $module->schemaPaths()))->toBe([realpath($package.'/database/migrations')])
        ->and($module->problemSources())->toBe([WebhooksProblemCodes::class])
        ->and(array_map(static fn (string $class): string => new ReflectionClass($class)->getShortName(), $module->commandClasses()))
        ->toBe(['RetryWebhooksCommand', 'PruneWebhooksCommand', 'RedeliverWebhooksCommand']);

    $contributions = array_map(static fn (SlotContribution $c): array => [$c->slot, $c->implementation, $c->id], $module->contributions());
    expect($contributions)->toContain([OutboxConsumer::class, WebhookFanoutConsumer::class, WebhookFanoutConsumer::ID])
        ->and(array_column($contributions, 0))->toContain(DoctorCheck::class);
});

it('ships its migrations in the package, in the block of numbers of the webhooks owner', function (): void {
    $files = array_map(basename(...), glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: []);

    expect($files)->toBe([
        '0001_01_01_000401_create_v_webhook_endpoints_table.php',
        '0001_01_01_000402_create_v_webhook_deliveries_table.php',
        '0001_01_01_000403_add_endpoint_feed_index_to_v_webhook_deliveries_table.php',
        '0001_01_01_000404_add_endpoint_foreign_key_index_to_v_webhook_deliveries_table.php',
    ]);
});

it('is offered by the provider of the package and activates only with vaulter.modules.webhooks', function (): void {
    $kernel = app(Kernel::class);

    expect($kernel->isValid())->toBeTrue()
        ->and($kernel->isActive('webhooks'))->toBeTrue()
        ->and(array_keys(app()->getProviders(VaulterWebhooksServiceProvider::class)))->toBe([VaulterWebhooksServiceProvider::class]);
});

it('mounts the REST surface, the commands and the delivery slot only while the module is active', function (): void {
    $routes = webhookRouteNames(app());
    $commands = array_keys(Artisan::all());

    expect($routes)->toEqual([
        'vaulter.v1.webhook-deliveries.index',
        'vaulter.v1.webhook-deliveries.redeliver',
        'vaulter.v1.webhook-endpoints.deactivate',
        'vaulter.v1.webhook-endpoints.destroy',
        'vaulter.v1.webhook-endpoints.index',
        'vaulter.v1.webhook-endpoints.rotate-secret',
        'vaulter.v1.webhook-endpoints.show',
        'vaulter.v1.webhook-endpoints.store',
        'vaulter.v1.webhook-endpoints.update',
    ])
        ->and($commands)->toContain('vaulter:webhooks:retry', 'vaulter:webhooks:prune', 'vaulter:webhooks:redeliver');

    $off = FreshApplication::boot(['vaulter.modules.webhooks' => false]);

    expect($off->make(Kernel::class)->isActive('webhooks'))->toBeFalse()
        ->and(webhookRouteNames($off))->toBe([])
        ->and(array_keys($off->make(ConsoleKernel::class)->all()))->not->toContain('vaulter:webhooks:retry')
        ->and($off->make(Kernel::class)->extensions()->resolve(OutboxConsumer::class))->toBe([]);
});

it('takes the config defaults of vaulter-webhooks while active and stays out of the kernel config', function (): void {
    expect(config('vaulter-webhooks.retry_jitter'))->toBe(0.1)
        ->and(config('vaulter-webhooks.delivery.allowed_schemes'))->toBe(['http', 'https'])
        ->and(config('vaulter-webhooks.models.webhook_endpoint'))->toBe(WebhookEndpoint::class)
        ->and(config('vaulter.webhooks'))->toBeNull()
        ->and(config('vaulter.models.webhook_endpoint'))->toBeNull();
});

it('refuses to activate without the drive module it requires', function (): void {
    $kernel = FreshApplication::boot(['vaulter.modules' => ['drive' => false, 'webhooks' => true]])->make(Kernel::class);

    expect($kernel->isValid())->toBeFalse()
        ->and($kernel->reasons())->toContain('module "webhooks" is active but requires "drive", which is not active');
});

it('fails the boot of the active module on a malformed vaulter-webhooks value', function (): void {
    expect(fn (): Application => FreshApplication::boot(['vaulter-webhooks.retry_jitter' => 0.9]))
        ->toThrow(InvalidConfiguration::class, 'vaulter-webhooks.retry_jitter');
});

it('does not read the configuration of an inactive module at boot', function (): void {
    $app = FreshApplication::boot(['vaulter.modules.webhooks' => false, 'vaulter-webhooks.retry_jitter' => 0.9]);

    expect($app->make(Kernel::class)->isActive('webhooks'))->toBeFalse();
});
