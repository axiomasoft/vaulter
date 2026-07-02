<?php

declare(strict_types=1);

namespace Vaulter\DocDrive\Http\Requests;

use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * Validates a named-version snapshot request (§2.5 / M4a): a non-empty label and an
 * optional keep_forever pin.
 */
final class CreateNamedVersionRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'keep_forever' => ['nullable', 'boolean'],
        ];
    }

    public function label(): string
    {
        return $this->string('label')->toString();
    }

    public function keepForever(): bool
    {
        return $this->boolean('keep_forever');
    }
}
