<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests;

use Illuminate\Validation\Rule;
use Vaulter\VaulterManager;

final class ExportDocumentRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['nullable', 'string', Rule::in($this->exporterFormats())],
        ];
    }

    public function exportFormat(): string
    {
        $format = $this->input('format');

        return is_string($format) && $format !== '' ? $format : 'html';
    }

    /**
     * @return list<string>
     */
    private function exporterFormats(): array
    {
        return app(VaulterManager::class)->exporterFormats();
    }
}
