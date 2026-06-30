<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Webhooks;

use Illuminate\Validation\Rule;
use Vaulter\Data\Webhooks\RegisterWebhookEndpointData;
use Vaulter\Enums\Webhooks\WebhookEvent;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * Validates a register-webhook-endpoint request (master plan §2.11 / D14 / M3b) and
 * assembles its RegisterWebhookEndpointData command. The url must be a valid http(s)
 * URL; every subscribed event must be one of the closed WebhookEvent set; is_active
 * defaults true. The signing_secret is NOT accepted from the client — the Action mints
 * it. The controller authorizes the manage ability first.
 */
final class RegisterWebhookEndpointRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookEvent::names())],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function toData(): RegisterWebhookEndpointData
    {
        return new RegisterWebhookEndpointData(
            url: $this->string('url')->toString(),
            events: $this->eventNames(),
            isActive: $this->has('is_active') ? $this->boolean('is_active') : true,
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
