<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Vaulter\Documents\Content\TipTap\TipTapCodec;
use Vaulter\Documents\Support\DocumentTypeKey;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\Types\SpreadsheetDocumentType;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Support\DriveNameRules;

/**
 * Validates a create-document-node request. The destination parent and target
 * storage are resolved by the controller; this request owns the name, the document
 * type and the raw initial content, which DocumentOperations::create() decodes through
 * the type's codec.
 *
 * The type is the `type` key (`vendor/name`). The JSON fields of the 0.x client still
 * select it until REST v1: `format` (richtext|table|template → vaulter/rich-text,
 * markdown → vaulter/markdown) and `mime` (the spreadsheet mime → vaulter/spreadsheet).
 * A type that is not registered is rejected on the field that named it.
 */
final class CreateDocumentNodeRequest extends VaulterFormRequest
{
    private const array SPREADSHEET_MIMES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
    ];

    private const string DOCUMENT_MIME = 'application/vnd.vaulter.document+json';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'name' => DriveNameRules::rules(),
            'parent_id' => ['nullable'],
            'type' => ['nullable', 'string', 'regex:/^[a-z0-9-]+\/[a-z0-9-]+$/'],
            'format' => ['nullable', 'string', 'in:richtext,table,template,markdown'],
            'mime' => ['nullable', 'string'],
            'content' => ['nullable', 'array'],
        ];

        // A TipTap-based type pins the {"type":"doc"} root; any other type's codec
        // validates its own shape.
        if ($this->typeKey() !== null && $this->types()->find($this->typeKey())?->codec() instanceof TipTapCodec) {
            $rules['content.type'] = ['required_with:content', 'string', 'in:doc'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $key = $this->typeKey();
            if ($key === null) {
                $validator->errors()->add('mime', 'This format has no editable document type.');

                return;
            }
            if (!$this->types()->has($key)) {
                $field = match (true) {
                    $this->filledString('type') !== null => 'type',
                    $this->filledString('mime') !== null => 'mime',
                    default => 'format',
                };
                $validator->errors()->add($field, 'This document type is not registered.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return DriveNameRules::messages();
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }

    public function parentId(): int|string|null
    {
        return $this->nullableScalarId('parent_id');
    }

    /**
     * The document type key the request selects, or null when its mime names no
     * document type.
     */
    public function typeKey(): ?string
    {
        $type = $this->filledString('type');
        if ($type !== null) {
            return $type;
        }

        $mime = $this->filledString('mime');
        if ($mime !== null && $mime !== self::DOCUMENT_MIME) {
            return in_array($mime, self::SPREADSHEET_MIMES, true) ? SpreadsheetDocumentType::KEY : null;
        }

        return DocumentTypeKey::fromFormat($this->filledString('format') ?? 'richtext');
    }

    /**
     * The raw initial content; null starts with the type's default.
     *
     * @return array<string, mixed>|null
     */
    public function content(): ?array
    {
        return $this->nullableArrayInput('content');
    }

    private function filledString(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function types(): DocumentTypeRegistry
    {
        return app(DocumentTypeRegistry::class);
    }
}
