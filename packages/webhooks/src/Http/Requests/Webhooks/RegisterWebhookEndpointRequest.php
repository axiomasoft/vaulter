<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Requests\Webhooks;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Vaulter\Attributes\Internal;
use Vaulter\Events\EventCatalog;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Webhooks\Contracts\OutboundUrlGuard;
use Vaulter\Webhooks\Data\RegisterWebhookEndpointInput;
use Vaulter\Webhooks\Exceptions\BlockedOutboundUrlException;

/**
 * Validates a register-webhook-endpoint request and
 * assembles its RegisterWebhookEndpointInput command. The url must be a valid http(s)
 * URL; every subscribed event must be an external type of the event catalogue; is_active
 * defaults true; drive_id optionally limits the endpoint to one drive's events. The
 * signing_secret is NOT accepted from the client — the Action mints it. The
 * controller authorizes the manage ability first.
 *
 * SSRF early-reject: the OutboundUrlGuard resolves + range-checks
 * the URL at registration too, so a target inside the internal network is refused with
 * a 422 instead of only failing at delivery. This is UX/defence-in-depth — the binding
 * barrier is on the delivery path (DNS can flip between register and deliver).
 */
#[Internal]
final class RegisterWebhookEndpointRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(EventCatalog $catalog): array
    {
        return [
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in($catalog->externalValues())],
            'is_active' => ['nullable', 'boolean'],
            'drive_id' => ['nullable', 'alpha_dash:ascii', 'max:64'],
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
