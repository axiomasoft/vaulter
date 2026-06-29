<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests;

use Vaulter\Data\CreateCommentData;

final class StoreCommentRequest extends VaulterFormRequest
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

    public function toData(): CreateCommentData
    {
        $user = $this->user();

        return new CreateCommentData(
            body: $this->string('body')->toString(),
            authorType: $user !== null ? $user::class : 'guest',
            authorId: $this->actorId() ?? 0,
            parentId: $this->nullableScalarId('parent_id'),
            anchor: $this->nullableArrayInput('anchor'),
        );
    }
}
