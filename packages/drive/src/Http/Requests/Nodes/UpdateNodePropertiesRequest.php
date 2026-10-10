<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Nodes;

use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * PATCH nodes/{node}/properties: one namespace at a time, the keys to write and the
 * keys to remove. Namespace and key syntax, value sizes and the reserved `vaulter`
 * namespaces are the operation's rules (422 invalid_properties).
 */
final class UpdateNodePropertiesRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'namespace' => ['required', 'string', 'max:191'],
            'set' => ['sometimes', 'array'],
            'set.*' => ['string'],
            'unset' => ['sometimes', 'array', 'list'],
            'unset.*' => ['string'],
        ];
    }

    public function propertyNamespace(): string
    {
        return $this->string('namespace')->toString();
    }

    /** @return array<string, string> */
    public function set(): array
    {
        $set = [];
        foreach ($this->arrayInput('set') as $key => $value) {
            if (is_string($value)) {
                $set[(string) $key] = $value;
            }
        }

        return $set;
    }

    /** @return list<string> */
    public function unset(): array
    {
        return array_values(array_filter($this->arrayInput('unset'), is_string(...)));
    }
}
