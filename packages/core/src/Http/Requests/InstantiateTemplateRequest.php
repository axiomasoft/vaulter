<?php

declare(strict_types=1);

namespace DocVault\Http\Requests;

use DocVault\Data\InstantiateTemplateData;
use DocVault\Enums\DocumentType;
use Illuminate\Validation\Rule;

final class InstantiateTemplateRequest extends DocVaultFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'variables' => ['nullable', 'array'],
            'title' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in($this->instanceTypes())],
            'workspace_type' => ['sometimes', 'string', 'max:255'],
            'workspace_id' => ['sometimes'],
            'folder_id' => ['nullable'],
        ];
    }

    public function toData(): InstantiateTemplateData
    {
        return new InstantiateTemplateData(
            variables: $this->variablesMap(),
            title: $this->has('title') ? $this->string('title')->toString() : null,
            type: $this->has('type') ? $this->string('type')->toString() : null,
            workspaceType: $this->has('workspace_type') ? $this->string('workspace_type')->toString() : null,
            workspaceId: $this->has('workspace_id') ? $this->scalarId('workspace_id') : null,
            folderId: $this->nullableScalarId('folder_id'),
            actorId: $this->actorId(),
        );
    }

    /**
     * Editable document types a template may be instantiated as (a template
     * cannot produce another template).
     *
     * @return list<string>
     */
    private function instanceTypes(): array
    {
        return array_values(array_filter(
            DocumentType::values(),
            static fn (string $type): bool => $type !== DocumentType::Template->value,
        ));
    }

    /**
     * @return array<string, string>
     */
    private function variablesMap(): array
    {
        $raw = $this->input('variables');

        if (!is_array($raw)) {
            return [];
        }

        $variables = [];
        foreach ($raw as $key => $value) {
            if (is_scalar($value)) {
                $variables[(string) $key] = (string) $value;
            }
        }

        return $variables;
    }
}
