<?php

declare(strict_types=1);

namespace Vaulter\Observability;

/**
 * Closed span literals for P8.8-selected targets (observability-spec §20.2).
 */
final class TracingSpanNames
{
    public const string UPLOAD_SWEEP = 'vaulter.action.upload.sweep';

    public const string WEBHOOK_RELAY = 'vaulter.action.webhook.relay';

    public const string WEBHOOK_RETRY = 'vaulter.action.webhook.retry';

    public const string WEBHOOK_DELIVERY = 'vaulter.job.webhook.delivery';
}
