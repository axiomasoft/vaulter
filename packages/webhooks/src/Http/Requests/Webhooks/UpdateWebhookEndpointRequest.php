<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Requests\Webhooks;

use Illuminate\Validation\Rule;
use Vaulter\Attributes\Internal;
use Vaulter\Events\EventCatalog;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Webhooks\Data\UpdateWebhookEndpointInput;

/**
 * Validates an update-webhook-endpoint request and
 * assembles its UpdateWebhookEndpointInput command. Every field is optional (a PATCH
 * touches only what it sends); a present field must still be valid. drive_id moves
 * the drive filter to another drive. signing_secret is never updatable. The controller authorizes the manage ability first.
 */
#[Internal]
final class UpdateWebhookEndpointRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(EventCatalog $catalog): array
    {
        return [
            'url' => ['sometimes', 'url', 'max:2048'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', Rule::in($catalog->externalValues())],
            'is_active' => ['sometimes', 'boolean'],
            'drive_id' => ['sometimes', 'alpha_dash:ascii', 'max:64'],
        ];
    }

    public function toData(): UpdateWebhookEndpointInput
    {
        return new UpdateWebhookEndpointInput(
            url: $this->has('url') ? $this->string('url')->toString() : null,
            events: $this->has('events') ? $this->eventNames() : null,
            isActive: $this->has('is_active') ? $this->boolean('is_active') : null,
            driveId: $this->filled('drive_id') ? $this->string('drive_id')->toString() : null,
        );
    }

    /**
     * The de-duplicated, re-indexed list of subscribed event names.
     *
     * @return list<string>
     */
    private function eventNames(): array
    {
        $events = $this->input('events');

        if (!is_array($events)) {
            return [];
        }

        $names = [];

        foreach ($events as $event) {
            if (is_string($event)) {
                $names[] = $event;
            }
        }

        return array_values(array_unique($names));
    }
}
