<?php

declare(strict_types=1);

namespace Vaulter\Tests\Schema;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared trusted-fixture-builder helper (P1.4/F-P1.8-02). Node/Storage/File/
 * FileRevision/Blob/… now carry a closed $fillable/$guarded list (the HTTP
 * hardening this suite exercises), so schema-level fixture builders that need
 * service columns (owner_id, node_id, blob_id, status, …) go through
 * forceFill() — the same trusted-seam pattern the production write paths use
 * (NodeStoreRepository et al.), never a raw ::create()/fill() that the new
 * guard would silently truncate.
 */
final class ForceCreates
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel> $model
     * @param  array<string, mixed> $attributes
     * @return TModel
     */
    public static function make(string $model, array $attributes): Model
    {
        $instance = new $model;
        $instance->forceFill($attributes);
        $instance->save();

        return $instance;
    }
}
