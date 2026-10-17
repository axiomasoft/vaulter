<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Resources\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Webhooks\Models\WebhookDelivery;

/**
 * One delivery of an event to an endpoint. The payload and the raw error text stay
 * server-side; the last response status is reported when the endpoint answered.
 *
 * @mixin WebhookDelivery
 */
#[Internal]
final class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var WebhookDelivery $delivery */
        $delivery = $this->resource;

        return [
            'id' => SnapshotValue::id($delivery->getKey()),
            'event_id' => $delivery->event_id,
            'event_type' => $delivery->event,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'next_retry_at' => SnapshotValue::iso($delivery->next_retry_at),
            'last_status_code' => self::statusCode($delivery->last_error),
            'created_at' => SnapshotValue::iso($delivery->created_at),
        ];
    }

    /** A failed attempt that got an HTTP answer records it as "HTTP <status>". */
    private static function statusCode(?string $lastError): ?int
    {
        return $lastError !== null && preg_match('/^HTTP (\d{3})$/D', $lastError, $match) === 1 ? (int) $match[1] : null;
    }
}
