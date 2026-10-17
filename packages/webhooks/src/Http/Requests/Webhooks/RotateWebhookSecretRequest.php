<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Requests\Webhooks;

use Vaulter\Attributes\Internal;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * Rotate an endpoint's signing secret. `overlap_seconds` keeps the old secret valid
 * alongside the new one for that long, so the subscriber can switch without dropping
 * deliveries; the action caps it.
 */
#[Internal]
final class RotateWebhookSecretRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'overlap_seconds' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function overlapSeconds(): ?int
    {
        return $this->filled('overlap_seconds') ? $this->integer('overlap_seconds') : null;
    }
}
