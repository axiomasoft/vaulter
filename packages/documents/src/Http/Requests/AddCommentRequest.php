<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Requests;

use Vaulter\Attributes\Internal;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Rules\CommentAnchorBounds;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * POST nodes/{node}/comments: a comment's body, its parent comment (a reply) and an
 * optional anchor. The author is not part of the payload: CommentOperations records the
 * acting user (their registered morph alias, never a raw FQCN).
 */
#[Internal]
final class AddCommentRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'string', 'max:191'],
            'anchor' => ['nullable', 'array', new CommentAnchorBounds],
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
