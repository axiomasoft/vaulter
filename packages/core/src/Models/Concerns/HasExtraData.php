<?php

declare(strict_types=1);

namespace Vaulter\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;

/**
 * The extra_data jsonb escape-hatch (D13). Sparse, host-specific metadata lives
 * here without a migration; promote a queried/sortable/FK field to a real column
 * with a follow-up migration when it stops being sparse (master plan §4.5).
 *
 * extra_data is cast to an ArrayObject so consumers can mutate keys in place
 * (`$node->extra_data['k'] = 'v'`) and the change is persisted.
 *
 * @mixin Model
 */
trait HasExtraData
{
    public function initializeHasExtraData(): void
    {
        $this->mergeCasts([
            'extra_data' => AsArrayObject::class,
        ]);
    }
}
