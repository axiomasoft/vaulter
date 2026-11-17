<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Search;

use Packman\Attributes\Internal;
use Vaulter\Http\Requests\VaulterFormRequest;

/** GET drives/{drive}/search: the name term; paging is PageResponse's. */
#[Internal]
final class SearchNodesRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:255'],
        ];
    }

    public function term(): string
    {
        return $this->string('q')->toString();
    }
}
