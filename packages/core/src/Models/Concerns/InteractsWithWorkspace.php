<?php

declare(strict_types=1);

namespace Vaulter\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vaulter\ValueObjects\Drive\WorkspaceRef;

/**
 * Adds the polymorphic workspace relation (workspace_type / workspace_id) and a
 * scope to filter records by a {@see WorkspaceRef}.
 */
trait InteractsWithWorkspace
{
    /**
     * @return MorphTo<Model, $this>
     */
    public function workspace(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForWorkspace(Builder $query, WorkspaceRef $workspace): Builder
    {
        return $query
            ->where('workspace_type', $workspace->type)
            ->where('workspace_id', $workspace->id);
    }
}
