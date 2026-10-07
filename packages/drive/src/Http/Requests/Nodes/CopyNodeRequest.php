<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Nodes;

use Vaulter\Drive\Data\Nodes\CopyNodeInput;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Models\Drive\Node;

/**
 * Validates a copy request (§2.4): the source node is route-model resolved, the
 * destination parent (null = storage root) and an optional new name come from the
 * body. toData() takes the already-resolved nodes; an empty name override is
 * normalised to null so the Action keeps the source's name.
 */
final class CopyNodeRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['nullable'],
            'name' => ['nullable', 'string', 'max:255', 'regex:/^[^\/\\\\]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The name must not contain path separators (/ or \\).',
        ];
    }

    public function parentId(): int|string|null
    {
        return $this->nullableScalarId('parent_id');
    }

    public function name(): ?string
    {
        $name = $this->input('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function toData(Node $node, ?Node $into): CopyNodeInput
    {
        return new CopyNodeInput(
            node: $node,
            into: $into,
            name: $this->name(),
        );
    }
}
