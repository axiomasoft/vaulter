<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Webhooks;

use Illuminate\Validation\Rule;
use Vaulter\Data\Webhooks\UpdateWebhookEndpointInput;
use Vaulter\Enums\Events\EventType;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * Validates an update-webhook-endpoint request (master plan §2.11 / D14 / M3b) and
 * assembles its UpdateWebhookEndpointInput command. Every field is optional (a PATCH
 * touches only what it sends); a present field must still be valid. signing_secret is
 * never updatable. The controller authorizes the manage ability first.
 */
final class UpdateWebhookEndpointRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['sometimes', 'url', 'max:2048'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(EventType::names())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): UpdateWebhookEndpointInput
    {
        return new UpdateWebhookEndpointInput(
            url: $this->has('url') ? $this->string('url')->toString() : null,
            events: $this->has('events') ? $this->eventNames() : null,
            isActive: $this->has('is_active') ? $this->boolean('is_active') : null,
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
