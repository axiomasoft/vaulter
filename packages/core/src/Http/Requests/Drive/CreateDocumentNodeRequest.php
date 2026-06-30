<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Drive;

use Vaulter\Enums\Documents\DocumentType;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\ValueObjects\Documents\TipTapContent;

/**
 * Validates a create-document-node request (§2.5 / M4a). The destination parent +
 * target storage are route/repository resolved by the controller; this Request owns
 * the name + initial content + format validation and builds the value objects the
 * controller hands to CreateDocumentNodeAction.
 */
final class CreateDocumentNodeRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => DriveNameRules::rules(),
            'parent_id' => ['nullable'],
            'format' => ['nullable', 'string', 'in:'.implode(',', DocumentType::values())],
            'content' => ['nullable', 'array'],
            'content.type' => ['required_with:content', 'string', 'in:doc'],
        ];
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

    public function documentFormat(): string
    {
        $format = $this->string('format')->toString();

        return $format !== '' ? $format : DocumentType::RichText->value;
    }

    /**
     * The initial TipTap content — an empty doc when none is supplied.
     */
    public function content(): TipTapContent
    {
        $content = $this->nullableArrayInput('content');

        if ($content === null) {
            return TipTapContent::empty();
        }

        return TipTapContent::fromArray($content);
    }
}
