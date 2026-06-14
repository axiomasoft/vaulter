<?php

declare(strict_types=1);

namespace DocVault\Http\Requests;

use DocVault\DocVaultManager;
use Illuminate\Validation\Rule;

final class ExportDocumentRequest extends DocVaultFormRequest
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
        return app(DocVaultManager::class)->exporterFormats();
    }
}
