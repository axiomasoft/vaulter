<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Changes;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Configuration\ChangesConfig;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * A page of the change journal: the cursor from changes/start or from the previous
 * page, and how many changes at most.
 */
#[Internal]
final class ListChangesRequest extends VaulterFormRequest
{
    public const int DEFAULT_LIMIT = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(ChangesConfig $changes): array
    {
        return [
            'cursor' => ['required', 'string', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'between:1,'.$changes->maxPageSize()],
        ];
    }

    public function cursor(): string
    {
        return $this->string('cursor')->toString();
    }

    public function limit(): int
    {
        return $this->query->has('limit') ? $this->integer('limit') : self::DEFAULT_LIMIT;
    }
}
