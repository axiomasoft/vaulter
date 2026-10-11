<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Carbon\CarbonImmutable;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

final readonly class WebhooksOutboxLagCheck implements DoctorCheck
{
    /** Seconds a due outbox row may wait for the relay before the lag is reported. */
    private const int MAX_LAG = 600;

    public function __construct(private VaulterConfig $config) {}

    public function key(): string
    {
        return 'core.webhooks.outbox_lag';
    }

    public function title(): string
    {
        return 'Webhook outbox lag';
    }

    public function run(DoctorContext $context): array
    {
        if (!$this->config->featureEnabled('webhooks')) {
            return [DoctorFinding::ok(code: 'core.webhooks.outbox_lag_skipped', message: 'Webhooks are disabled.', evidence: ['skipped' => true])];
        }

        $outbox = $this->config->model('outbox');
        // Rows are written in the application's timezone; compare in it.
        $now = CarbonImmutable::instance($context->now)->setTimezone(date_default_timezone_get());
        $oldest = $outbox::query()->where('status', 'pending')->where('available_at', '<=', $now)->min('available_at');
        $lag = is_string($oldest) ? max(0, $now->getTimestamp() - CarbonImmutable::parse($oldest, date_default_timezone_get())->getTimestamp()) : 0;

        if ($lag >= self::MAX_LAG) {
            return [DoctorFinding::warning(
                code: 'core.webhooks.outbox_lagging',
                message: 'The oldest due outbox row has waited '.intdiv($lag, 60).' minutes for the relay.',
                hint: 'run the queue worker and the scheduler (vaulter:webhooks:relay)',
                evidence: ['lag_seconds' => $lag, 'limit_seconds' => self::MAX_LAG],
            )];
        }

        return [DoctorFinding::ok(code: 'core.webhooks.outbox_current', message: 'The outbox is relayed in time.', evidence: ['lag_seconds' => $lag])];
    }
}
