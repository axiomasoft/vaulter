<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Requests;

use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * The store payload for a node comment (ADR-0014 шаг 1). The author is not part of the
 * payload: CommentOperations records the acting user (their registered morph alias,
 * never a raw FQCN).
 */
final class StoreNodeCommentRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable'],
            'anchor' => ['nullable', 'array'],
        ];
    }

    public function toData(): AddCommentInput
    {
        return new AddCommentInput(
            body: $this->string('body')->toString(),
            parentId: $this->nullableScalarId('parent_id'),
            anchor: $this->nullableArrayInput('anchor'),
        );
    }
}
