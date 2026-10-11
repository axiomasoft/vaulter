<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Requests;

use Vaulter\Http\Requests\VaulterFormRequest;

/** POST documents/{node}/collaboration/session: the schema version the client's editor speaks, when it says. */
final class CreateCollaborationSessionRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'schema_version' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function clientSchemaVersion(): ?int
    {
        $version = $this->input('schema_version');

        return is_int($version) ? $version : (is_string($version) && ctype_digit($version) ? (int) $version : null);
    }
}
