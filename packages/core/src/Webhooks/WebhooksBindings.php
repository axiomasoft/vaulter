<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Configuration\WebhooksConfig;
use Vaulter\Contracts\Webhooks\HostResolver;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Modules\ModuleBindings;
use Vaulter\Policies\WebhookEndpointPolicy;

/** The container wiring of the webhooks module: the outbound SSRF guard and the endpoint policy. */
final class WebhooksBindings implements ModuleBindings
{
    public function register(Application $app): void
    {
        // Outbound webhook SSRF guard: the host resolver is the injectable DNS seam (A + AAAA), and the
        // guard is host-swappable via the delivery path (DeliverWebhook) and — as an early reject — at
        // registration.
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
        $model = $app->make(VaulterConfig::class)->model('webhook_endpoint');
        if ($model !== WebhookEndpoint::class) {
            Gate::policy($model, WebhookEndpointPolicy::class);
        }
    }
}
