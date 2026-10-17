<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Modules\ModuleBindings;
use Vaulter\Webhooks\Configuration\WebhooksConfig;
use Vaulter\Webhooks\Contracts\HostResolver;
use Vaulter\Webhooks\Contracts\OutboundUrlGuard;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Policies\WebhookEndpointPolicy;

/** The container wiring of the webhooks module: the outbound SSRF guard and the endpoint policy. */
#[Internal]
final class WebhooksBindings implements ModuleBindings
{
    public function register(Application $app): void
    {
        // Outbound webhook SSRF guard: the host resolver is the injectable DNS seam (A + AAAA), and the
        // guard is host-swappable via the delivery path (DeliverWebhook) and — as an early reject — at
        // registration.
        $app->singleton(WebhooksConfig::class);
        $app->bind(HostResolver::class, SystemHostResolver::class);
        $app->bind(OutboundUrlGuard::class, static function (Application $app): OutboundUrlGuard {
            $guard = $app->make($app->make(WebhooksConfig::class)->urlGuard());
            if (!$guard instanceof OutboundUrlGuard) {
                throw new InvalidConfigurationException('[Vaulter] Outbound URL guard binding must return an OutboundUrlGuard.');
            }

            return $guard;
        });
    }

    public function boot(Application $app): void
    {
        // The webhook-endpoint management surface is a global admin resource, authorized by the
        // WebhookEndpointPolicy (manage ability).
        Gate::policy(WebhookEndpoint::class, WebhookEndpointPolicy::class);
        $config = $app->make(WebhooksConfig::class);
        // A malformed vaulter-webhooks.* value fails the boot of the active module, not the first delivery.
        $config->validate();
        $model = $config->model('webhook_endpoint');
        if ($model !== WebhookEndpoint::class) {
            Gate::policy($model, WebhookEndpointPolicy::class);
        }
    }
}
