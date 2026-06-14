<?php

declare(strict_types=1);

namespace DocVault\Http\Requests;

use DocVault\Enums\ExportFormat;
use Illuminate\Validation\Rule;

final class ExportDocumentRequest extends DocVaultFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['nullable', 'string', Rule::in(ExportFormat::values())],
        ];
    }

    public function exportFormat(): ExportFormat
    {
        return $this->enum('format', ExportFormat::class) ?? ExportFormat::Html;
    }
}
