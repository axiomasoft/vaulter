<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Documents\Data\Comments\CreateNodeCommentData;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * The store payload for a node comment (ADR-0014 шаг 1). The author morph is a
 * REGISTERED alias — $user->getMorphClass() (honours the host's enforceMorphMap) —
 * never a raw FQCN (the difference from the legacy StoreCommentRequest).
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

    public function toData(): CreateNodeCommentData
    {
        $user = $this->user();

        return new CreateNodeCommentData(
            body: $this->string('body')->toString(),
            authorType: $user instanceof Model ? $user->getMorphClass() : 'guest',
            authorId: $this->actorId() ?? 0,
            parentId: $this->nullableScalarId('parent_id'),
            anchor: $this->nullableArrayInput('anchor'),
        );
    }
}
