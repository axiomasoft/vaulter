<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Doctor\Checks;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Webhooks\Configuration\WebhooksConfig;

#[Internal]
final readonly class WebhooksDeliveriesCheck implements DoctorCheck
{
    public function __construct(private WebhooksConfig $config) {}

    public function key(): string
    {
        return 'core.webhooks.deliveries';
    }

    public function title(): string
    {
        return 'Webhook deliveries and endpoints';
    }

    public function run(DoctorContext $context): array
    {
        $endpoints = $this->config->model('webhook_endpoint');
        $deliveries = $this->config->model('webhook_delivery');
        // An owner who switches an endpoint off ('manual') also parks its open deliveries as dead
        // with the reason 'endpoint_disabled'; neither says anything about a failing receiver.
        $disabled = $endpoints::query()->whereNotNull('disabled_at')->where('disabled_reason', 'failing')->count();
        $dead = $deliveries::query()->where('status', 'dead')
            ->where(static fn (Builder $query) => $query->whereNull('last_error')->orWhere('last_error', '!=', 'endpoint_disabled'))->count();

        if ($disabled === 0 && $dead === 0) {
            return [DoctorFinding::ok(code: 'core.webhooks.deliveries_ok', message: 'No endpoint was disabled for failing and no delivery used up its retries.')];
        }

        return [DoctorFinding::warning(
            code: 'core.webhooks.deliveries_failing',
            message: $disabled.' endpoint(s) were disabled after failing and '.$dead.' delivery(ies) used up their retries.',
            hint: 'fix the receiver, re-enable the endpoint and run vaulter:webhooks:redeliver',
            evidence: ['disabled_endpoints' => $disabled, 'dead_deliveries' => $dead],
        )];
    }
}
