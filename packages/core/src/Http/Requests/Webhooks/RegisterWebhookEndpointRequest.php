<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Webhooks;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Data\Webhooks\RegisterWebhookEndpointInput;
use Vaulter\Enums\Events\EventType;
use Vaulter\Exceptions\Webhooks\BlockedOutboundUrlException;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * Validates a register-webhook-endpoint request (master plan §2.11 / D14 / M3b) and
 * assembles its RegisterWebhookEndpointInput command. The url must be a valid http(s)
 * URL; every subscribed event must be one of the closed EventType set; is_active
 * defaults true. The signing_secret is NOT accepted from the client — the Action mints
 * it. The controller authorizes the manage ability first.
 *
 * SSRF early-reject (§2.11 / F-P1.7-01): the OutboundUrlGuard resolves + range-checks
 * the URL at registration too, so a target inside the internal network is refused with
 * a 422 instead of only failing at delivery. This is UX/defence-in-depth — the binding
 * barrier is on the delivery path (DNS can flip between register and deliver).
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
            'events.*' => ['string', Rule::in(EventType::names())],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * After the base rules pass, refuse a URL the outbound guard would block (private/
     * loopback/link-local host, disallowed scheme) — an early SSRF reject at register.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('url')) {
                return;
            }

            $url = $this->input('url');

            if (!is_string($url)) {
                return;
            }

            try {
                app(OutboundUrlGuard::class)->isAllowed($url);
            } catch (BlockedOutboundUrlException) {
                $validator->errors()->add('url', 'The webhook URL resolves to a blocked or unreachable address.');
            }
        });
    }

    public function toData(): RegisterWebhookEndpointInput
    {
        return new RegisterWebhookEndpointInput(
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
